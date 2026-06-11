<?php

namespace App\Services;

use App\Models\SupportTicket;
use App\Models\TicketVisit;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TicketVisitService
{
    public function __construct(
        private AuditService $audit,
        private FileUploadService $files,
        private TicketPartService $parts,
        private SupportTicketService $tickets,
    ) {}

    /**
     * Registrar la llegada del técnico a la visita (check-in con geolocalización).
     *
     * @throws DomainException si el ticket es terminal o ya hay una visita en curso.
     */
    public function checkIn(SupportTicket $ticket, array $data, User $actor): TicketVisit
    {
        if (SupportTicketService::isTerminal($ticket->status)) {
            throw new DomainException("No se puede iniciar una visita en un ticket en estado '{$ticket->status}'");
        }

        if ($this->openVisit($ticket)) {
            throw new DomainException('Ya hay una visita en curso (sin check-out) para este ticket');
        }

        $visit = TicketVisit::create([
            'ticket_id' => $ticket->id,
            'technician_id' => $actor->id,
            'checkin_at' => $data['at'] ?? now(),
            'checkin_latitude' => $data['latitude'] ?? null,
            'checkin_longitude' => $data['longitude'] ?? null,
        ]);

        $this->audit->logStaffAction($actor, 'support_ticket.visit_checkin', 'TicketVisit', $visit->id, null, [
            'ticket_id' => $ticket->id,
            'latitude' => $visit->checkin_latitude,
            'longitude' => $visit->checkin_longitude,
        ]);

        return $visit;
    }

    /**
     * Registrar la salida del técnico (check-out) de la visita en curso.
     *
     * @throws DomainException si no hay una visita abierta.
     */
    public function checkOut(SupportTicket $ticket, array $data, User $actor): TicketVisit
    {
        $visit = $this->openVisit($ticket);
        if (!$visit) {
            throw new DomainException('No hay una visita en curso para hacer check-out');
        }

        $visit->update([
            'checkout_at' => $data['at'] ?? now(),
            'checkout_latitude' => $data['latitude'] ?? null,
            'checkout_longitude' => $data['longitude'] ?? null,
        ]);

        $this->audit->logStaffAction($actor, 'support_ticket.visit_checkout', 'TicketVisit', $visit->id, null, [
            'ticket_id' => $ticket->id,
            'duration_minutes' => $visit->fresh()->durationMinutes(),
        ]);

        return $visit->fresh();
    }

    /**
     * Cerrar la visita con el reporte de servicio: trabajo realizado, repuestos,
     * firma de conformidad del cliente, fotos y acta en PDF.
     *
     * @param  UploadedFile|string|null  $signature  Firma (archivo o data URI base64).
     * @param  UploadedFile[]  $photos
     * @param  array<array{stock_id:int, qty:int}>  $parts
     * @throws DomainException si el ticket es terminal.
     */
    public function createServiceReport(
        SupportTicket $ticket,
        array $data,
        $signature,
        array $photos,
        array $parts,
        User $actor,
    ): TicketVisit {
        if (SupportTicketService::isTerminal($ticket->status)) {
            throw new DomainException("No se puede reportar un ticket en estado '{$ticket->status}'");
        }

        $visit = DB::transaction(function () use ($ticket, $data, $signature, $photos, $parts, $actor) {
            // Reusar la visita abierta/sin reportar; si no existe, crear una.
            $visit = $this->openVisit($ticket)
                ?? $ticket->visits()->whereNull('reported_at')->latest()->first()
                ?? TicketVisit::create([
                    'ticket_id' => $ticket->id,
                    'technician_id' => $actor->id,
                    'checkin_at' => now(),
                ]);

            [$signaturePath, $signatureBinary] = $this->resolveSignature($ticket, $signature);

            // Repuestos consumidos (descuentan inventario vía TicketPartService).
            foreach ($parts as $part) {
                $this->parts->addPart($ticket, (int) $part['stock_id'], (int) $part['qty'], null, $actor);
            }

            // Fotos del servicio (reutiliza el flujo de adjuntos del ticket).
            if (!empty($photos)) {
                $this->tickets->addAttachments($ticket, $photos, $actor);
            }

            $visit->update([
                'work_done' => $data['work_done'],
                'client_signed_name' => $data['client_signed_name'] ?? null,
                'signature_path' => $signaturePath,
                'reported_at' => now(),
            ]);

            // Generar el acta en PDF y guardar su ruta.
            $pdfPath = $this->generateActaPdf($ticket->fresh(), $visit->fresh(), $signatureBinary);
            $visit->update(['report_pdf_path' => $pdfPath]);

            // Cierre formal opcional de la visita: resolver el ticket.
            if (!empty($data['resolve']) && SupportTicketService::isValidTransition($ticket->status, 'resuelto')) {
                $this->tickets->changeStatus($ticket, 'resuelto', $actor, 'Resuelto con reporte de servicio en sitio');
            }

            $this->audit->logStaffAction($actor, 'support_ticket.service_report', 'TicketVisit', $visit->id, null, [
                'ticket_id' => $ticket->id,
                'parts' => count($parts),
                'photos' => count($photos),
            ]);

            return $visit->fresh();
        });

        return $visit;
    }

    /**
     * Visita abierta (con check-in y sin check-out) del ticket, si existe.
     */
    private function openVisit(SupportTicket $ticket): ?TicketVisit
    {
        return $ticket->visits()
            ->whereNotNull('checkin_at')
            ->whereNull('checkout_at')
            ->latest('checkin_at')
            ->first();
    }

    /**
     * Persistir la firma desde un archivo subido o una cadena base64 (data URI).
     *
     * @param  UploadedFile|string|null  $signature
     * @return array{0: ?string, 1: ?string}  [ruta almacenada, binario para el PDF]
     */
    private function resolveSignature(SupportTicket $ticket, $signature): array
    {
        if ($signature instanceof UploadedFile) {
            $path = $this->files->uploadImage($signature, "tickets/{$ticket->id}/signatures", 'sign');

            return [$path, $signature->get()];
        }

        if (is_string($signature) && $signature !== '') {
            // Quitar el prefijo data URI si viene incluido.
            $payload = preg_replace('/^data:image\/\w+;base64,/', '', $signature);
            $binary = base64_decode($payload, true);

            if ($binary === false) {
                throw new DomainException('La firma base64 no es válida');
            }

            $path = "tickets/{$ticket->id}/signatures/sign_" . uniqid() . '_' . time() . '.png';
            Storage::disk(config('filesystems.default'))->put($path, $binary);

            return [$path, $binary];
        }

        return [null, null];
    }

    /**
     * Renderizar el acta de conformidad a PDF y almacenarla.
     */
    private function generateActaPdf(SupportTicket $ticket, TicketVisit $visit, ?string $signatureBinary): string
    {
        $signatureTag = $signatureBinary
            ? '<img src="data:image/png;base64,' . base64_encode($signatureBinary) . '" style="max-height:120px;" />'
            : '<em>Sin firma registrada</em>';

        $partsRows = '';
        foreach ($ticket->parts()->with('stock.product')->get() as $part) {
            $name = optional(optional($part->stock)->product)->name ?? 'Repuesto';
            $partsRows .= '<tr><td>' . e($name) . '</td><td style="text-align:center;">' . (int) $part->quantity . '</td></tr>';
        }
        if ($partsRows === '') {
            $partsRows = '<tr><td colspan="2"><em>Sin repuestos</em></td></tr>';
        }

        $client = e($ticket->client->name ?? '');
        $code = e($ticket->code);
        $technician = e($visit->technician->name ?? '');
        $workDone = nl2br(e($visit->work_done ?? ''));
        $signedName = e($visit->client_signed_name ?? '');
        $checkin = $visit->checkin_at?->toDateTimeString() ?? '—';
        $checkout = $visit->checkout_at?->toDateTimeString() ?? '—';
        $duration = $visit->durationMinutes();
        $durationLabel = $duration !== null ? "{$duration} min" : '—';

        $html = <<<HTML
        <html>
        <head><meta charset="utf-8"><style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; }
            h1 { font-size: 18px; margin-bottom: 0; }
            .muted { color: #666; }
            table { width: 100%; border-collapse: collapse; margin-top: 8px; }
            th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
            .section { margin-top: 18px; }
            .sign-box { margin-top: 30px; border-top: 1px solid #999; padding-top: 6px; width: 260px; }
        </style></head>
        <body>
            <h1>Acta de Conformidad de Servicio</h1>
            <p class="muted">Ticket {$code}</p>

            <table>
                <tr><th>Cliente</th><td>{$client}</td></tr>
                <tr><th>Técnico</th><td>{$technician}</td></tr>
                <tr><th>Check-in</th><td>{$checkin}</td></tr>
                <tr><th>Check-out</th><td>{$checkout}</td></tr>
                <tr><th>Tiempo en sitio</th><td>{$durationLabel}</td></tr>
            </table>

            <div class="section">
                <strong>Trabajo realizado</strong>
                <p>{$workDone}</p>
            </div>

            <div class="section">
                <strong>Repuestos utilizados</strong>
                <table>
                    <tr><th>Repuesto</th><th style="width:80px; text-align:center;">Cantidad</th></tr>
                    {$partsRows}
                </table>
            </div>

            <div class="sign-box">
                {$signatureTag}
                <div>{$signedName}</div>
                <div class="muted">Firma de conformidad del cliente</div>
            </div>
        </body>
        </html>
        HTML;

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        $path = "tickets/{$ticket->id}/reports/acta_{$visit->id}_" . time() . '.pdf';
        Storage::disk(config('filesystems.default'))->put($path, $dompdf->output());

        return $path;
    }
}
