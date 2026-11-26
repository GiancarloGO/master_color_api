<?php

use App\Models\SupportTicket;
use App\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makeFakeTicketForUpload(array $seed): SupportTicket
{
    return SupportTicket::create([
        'code' => 'SOP-TEST-' . uniqid(),
        'client_id' => $seed['client']->id,
        'category' => 'falla',
        'priority' => 'media',
        'subject' => 'Prueba de adjuntos',
        'description' => 'Descripción inicial',
        'status' => 'abierto',
        'channel' => 'app',
        'service_type' => 'remoto',
    ]);
}

it('permite al cliente subir imágenes adjuntas incluso si el nombre carece de extensión', function () {
    Storage::fake(config('filesystems.default', 's3'));
    $seed = supportSeed();
    $ticket = makeFakeTicketForUpload($seed);

    $withExt = UploadedFile::fake()->image('foto.jpg', 400, 300);
    // Simula un archivo de galería Android sin extensión en el nombre original.
    $noExtTemp = UploadedFile::fake()->image('android_gallery.jpg', 300, 300);
    $withoutExt = new UploadedFile(
        $noExtTemp->getPathname(),
        '1000001234',
        'image/jpeg',
        null,
        true
    );

    $res = $this->withHeader('Authorization', 'Bearer ' . clientToken($seed['client']))
        ->post('/api/client/support/tickets/' . $ticket->id . '/attachments', [
            'files' => [$withExt, $withoutExt],
        ], ['Accept' => 'application/json']);

    $res->assertCreated()
        ->assertJsonCount(2, 'data');

    $paths = \App\Models\TicketAttachment::where('ticket_id', $ticket->id)->pluck('file_path')->all();
    expect($paths)->toHaveCount(2);
    foreach ($paths as $path) {
        expect($path)->toMatch('/\.(jpg|jpeg)$/');
        Storage::disk(config('filesystems.default', 's3'))->assertExists($path);
    }
});

it('permite al personal subir adjuntos al ticket y devuelve URLs válidas', function () {
    Storage::fake(config('filesystems.default', 's3'));
    $seed = supportSeed();
    $ticket = makeFakeTicketForUpload($seed);

    $png = UploadedFile::fake()->image('captura.png', 200, 200);

    $res = $this->withHeader('Authorization', 'Bearer ' . staffToken($seed['user']))
        ->post('/api/support/tickets/' . $ticket->id . '/attachments', [
            'files' => [$png],
        ], ['Accept' => 'application/json']);

    $res->assertCreated()
        ->assertJsonCount(1, 'data');
    expect($res->json('data.0.url'))->not->toBeEmpty();
});

it('permite registrar acta de servicio con firma y fotos comprimidas', function () {
    Storage::fake(config('filesystems.default', 's3'));
    $seed = supportSeed();
    $ticket = makeFakeTicketForUpload($seed);

    $signature = UploadedFile::fake()->image('firma.png', 300, 120);
    $photo = UploadedFile::fake()->image('visita.jpg', 400, 300);

    $res = $this->withHeader('Authorization', 'Bearer ' . staffToken($seed['user']))
        ->post('/api/support/tickets/' . $ticket->id . '/service-report', [
            'work_done' => 'Cambio de fusor y limpieza',
            'client_signed_name' => 'Cliente Demo',
            'client_signature_file' => $signature,
            'photos' => [$photo],
        ], ['Accept' => 'application/json']);

    $res->assertCreated();
    expect(\App\Models\TicketAttachment::where('ticket_id', $ticket->id)->count())->toBe(1);
});

it('rechaza con 422 un archivo no permitido o un message_id de otro ticket', function () {
    Storage::fake(config('filesystems.default', 's3'));
    $seed = supportSeed();
    $ticket1 = makeFakeTicketForUpload($seed);
    $ticket2 = makeFakeTicketForUpload($seed);
    $foreignMsg = TicketMessage::create([
        'ticket_id' => $ticket2->id,
        'author_type' => 'client',
        'author_id' => $seed['client']->id,
        'author_name' => $seed['client']->name,
        'body' => 'Otro ticket',
        'is_internal' => false,
    ]);

    $invalid = UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf');
    $resInvalid = $this->withHeader('Authorization', 'Bearer ' . clientToken($seed['client']))
        ->post('/api/client/support/tickets/' . $ticket1->id . '/attachments', [
            'files' => [$invalid],
        ], ['Accept' => 'application/json']);

    $resInvalid->assertStatus(422);

    $img = UploadedFile::fake()->image('ok.jpg', 100, 100);
    $resForeignMsg = $this->withHeader('Authorization', 'Bearer ' . clientToken($seed['client']))
        ->post('/api/client/support/tickets/' . $ticket1->id . '/attachments', [
            'files' => [$img],
            'message_id' => $foreignMsg->id,
        ], ['Accept' => 'application/json']);

    $resForeignMsg->assertStatus(422);
});
