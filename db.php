<?php
require_once __DIR__ . '/login.php';

function db_connect(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        global $host, $database, $username, $password;
        $dsn = "mysql:host={$host};dbname={$database};charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function db_create_job(string $job_id, string $session_id, array $p): void {
    $pdo = db_connect();
    $pdo->prepare("
        INSERT INTO jobs (
            job_id, session_id, genome_filename, organism,
            carbon_source, carbon_conc, carbon_unit,
            nitrogen_source, nitrogen_conc, nitrogen_unit,
            ph, temperature, rpm, volume, duration, inoculum,
            status, submitted_at
        ) VALUES (
            :job_id, :session_id, :genome_filename, :organism,
            :carbon_source, :carbon_conc, :carbon_unit,
            :nitrogen_source, :nitrogen_conc, :nitrogen_unit,
            :ph, :temperature, :rpm, :volume, :duration, :inoculum,
            'pending', NOW()
        )
    ")->execute([
        ':job_id'          => $job_id,
        ':session_id'      => $session_id,
        ':genome_filename' => $p['genome_filename']  ?? '',
        ':organism'        => $p['organism']         ?? '',
        ':carbon_source'   => $p['carbon_source']    ?? '',
        ':carbon_conc'     => $p['carbon_conc']      ?? null,
        ':carbon_unit'     => $p['carbon_unit']      ?? 'g/L',
        ':nitrogen_source' => $p['nitrogen_source']  ?? '',
        ':nitrogen_conc'   => $p['nitrogen_conc']    ?? null,
        ':nitrogen_unit'   => $p['nitrogen_unit']    ?? 'g/L',
        ':ph'              => $p['ph']               ?? null,
        ':temperature'     => $p['temperature']      ?? null,
        ':rpm'             => $p['rpm']              ?? null,
        ':volume'          => $p['volume']           ?? null,
        ':duration'        => $p['duration']         ?? null,
        ':inoculum'        => $p['inoculum']         ?? null,
    ]);
}

function db_update_status(string $job_id, string $status, ?string $message = null): void {
    db_connect()->prepare("
        UPDATE jobs
        SET status = :status, status_message = :msg, updated_at = NOW()
        WHERE job_id = :job_id
    ")->execute([':status' => $status, ':msg' => $message, ':job_id' => $job_id]);
}

function db_save_results(string $job_id, array $r): void {
    $pdo  = db_connect();
    $meta = $r['bio_metadata'] ?? [];

    $pdo->prepare("
        UPDATE jobs SET
            status         = 'done',
            taxon_detected = :taxon,
            taxon_id       = :taxon_id,
            organism_type  = :org_type,
            model_xml      = :model_xml,
            completed_at   = NOW(),
            updated_at     = NOW()
        WHERE job_id = :job_id
    ")->execute([
        ':taxon'     => $r['taxon_detected']       ?? null,
        ':taxon_id'  => $r['taxon_id']             ?? null,
        ':org_type'  => $meta['organism_type']     ?? null,
        ':model_xml' => $r['model_xml']            ?? null,
        ':job_id'    => $job_id,
    ]);

    $pdo->prepare("
        REPLACE INTO bio_metadata
            (job_id, organism_type, maintenance_coeff, required_supplements, tax_id)
        VALUES
            (:job_id, :org_type, :maintenance, :supplements, :tax_id)
    ")->execute([
        ':job_id'      => $job_id,
        ':org_type'    => $meta['organism_type']           ?? null,
        ':maintenance' => $meta['maintenance_coefficient'] ?? null,
        ':supplements' => isset($meta['required_supplements'])
                            ? implode(',', $meta['required_supplements'])
                            : null,
        ':tax_id'      => $meta['tax_id']                 ?? null,
    ]);
}

function db_get_job(string $job_id): ?array {
    $stmt = db_connect()->prepare("
        SELECT j.*, b.maintenance_coeff, b.required_supplements AS supplements_csv
        FROM jobs j
        LEFT JOIN bio_metadata b ON b.job_id = j.job_id
        WHERE j.job_id = :job_id
        LIMIT 1
    ");
    $stmt->execute([':job_id' => $job_id]);
    $row = $stmt->fetch();
    if (!$row) return null;

    // Reconstruct bio_metadata array for the JS layer
    $row['bio_metadata'] = [
        'organism_type'           => $row['organism_type']  ?? null,
        'maintenance_coefficient' => $row['maintenance_coeff'] !== null
                                        ? (float)$row['maintenance_coeff'] : null,
        'required_supplements'    => $row['supplements_csv']
                                        ? explode(',', $row['supplements_csv']) : [],
        'tax_id'                  => $row['taxon_id']       ?? null,
    ];
    return $row;
}

function db_get_jobs_by_session(string $session_id): array {
    $stmt = db_connect()->prepare("
        SELECT job_id, genome_filename, organism, status,
               submitted_at, completed_at, taxon_detected, organism_type
        FROM jobs
        WHERE session_id = :sid
        ORDER BY submitted_at DESC
        LIMIT 50
    ");
    $stmt->execute([':sid' => $session_id]);
    return $stmt->fetchAll();
}

function db_get_example_job(): ?array {
    $stmt = db_connect()->prepare("
        SELECT * FROM jobs WHERE is_example = 1 ORDER BY submitted_at DESC LIMIT 1
    ");
    $stmt->execute();
    return $stmt->fetch() ?: null;
}
