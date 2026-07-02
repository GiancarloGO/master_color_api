<?php

return [
    // Días de retención de la tabla `audit_logs`. Los registros más antiguos se
    // purgan con el comando `logs:prune` (programado en el scheduler).
    // 0 o valor negativo desactiva la purga (retención indefinida).
    'retention_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 180),
];
