<?php
require_once __DIR__ . '/db.php';

$job_id = preg_replace('/[^a-zA-Z0-9_\.]/', '', $_GET['job_id'] ?? '');
if (!$job_id) { http_response_code(400); exit('Missing job_id'); }

$model_xml = null;

try {
    $row = db_get_job($job_id);
    if ($row && $row['model_xml']) {
        $model_xml = $row['model_xml'];
    }
} catch (Exception $e) {}

if (!$model_xml) {
    $xml_path = __DIR__ . '/jobs/' . $job_id . '/model.xml';
    if (file_exists($xml_path)) {
        $model_xml = file_get_contents($xml_path);
    }
}

if (!$model_xml) {
    http_response_code(404);
    exit('Model not found for job: ' . htmlspecialchars($job_id));
}

header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: inline; filename="model_' . $job_id . '.xml"');
echo $model_xml;
