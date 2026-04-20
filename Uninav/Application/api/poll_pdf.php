<?php
require_once __DIR__ . '/../includes/helpers.php';
require_admin();

$pid = (int)($_GET['id'] ?? 0);
$p = db()->prepare('SELECT * FROM polls WHERE id=?'); $p->execute([$pid]); $poll=$p->fetch();
if (!$poll) { http_response_code(404); die('Poll not found.'); }

$opts = db()->prepare('SELECT * FROM poll_options WHERE poll_id=?'); $opts->execute([$pid]); $options = $opts->fetchAll();
$counts = db()->prepare('SELECT option_id, COUNT(*) c FROM poll_votes WHERE poll_id=? GROUP BY option_id'); $counts->execute([$pid]);
$tally = []; foreach ($counts->fetchAll() as $r) $tally[$r['option_id']] = (int)$r['c'];
$total = array_sum($tally) ?: 1;

$voters = db()->prepare('SELECT pv.*, u.owner_name, u.tower, u.house_number, po.option_text
                         FROM poll_votes pv
                         JOIN users u ON u.id=pv.user_id
                         JOIN poll_options po ON po.id=pv.option_id
                         WHERE pv.poll_id=? ORDER BY pv.created_at');
$voters->execute([$pid]); $votes = $voters->fetchAll();

/*
 * Minimal PDF generator: no external library. Builds a single-page
 * PDF containing the report text. Good enough for downloads without
 * pulling in vendored dependencies.
 */
function pdf_escape($s){ return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $s); }

$lines = [];
$lines[] = 'Uninav Society - Poll Report';
$lines[] = 'Poll: ' . $poll['question'];
$lines[] = 'Status: ' . ($poll['active']?'Active':'Closed');
$lines[] = 'Generated: ' . date('d M Y H:i');
$lines[] = '';
$lines[] = 'Results:';
foreach ($options as $o) {
    $c = $tally[$o['id']] ?? 0;
    $pct = round($c*100/$total, 1);
    $lines[] = '  - ' . $o['option_text'] . '  : ' . $c . ' votes (' . $pct . '%)';
}
$lines[] = '';
$lines[] = 'Total votes: ' . (array_sum($tally));
$lines[] = '';
$lines[] = 'Voters:';
foreach ($votes as $v) {
    $lines[] = '  - ' . $v['owner_name'] . ' (' . $v['tower'] . '-' . $v['house_number'] . ') -> ' . $v['option_text'] . ' @ ' . $v['created_at'];
}

// Build PDF content stream
$y = 760; $stream = "BT\n/F1 12 Tf\n";
foreach ($lines as $i => $line) {
    $stream .= sprintf("1 0 0 1 40 %d Tm\n(%s) Tj\n", $y, pdf_escape($line));
    $y -= 16;
    if ($y < 40) break;
}
$stream .= "ET\n";

$objects = [];
$objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
$objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
$objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>";
$objects[4] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
$objects[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

$pdf = "%PDF-1.4\n";
$offsets = [];
foreach ($objects as $n => $obj) {
    $offsets[$n] = strlen($pdf);
    $pdf .= "$n 0 obj\n$obj\nendobj\n";
}
$xref = strlen($pdf);
$pdf .= "xref\n0 " . (count($objects)+1) . "\n0000000000 65535 f \n";
foreach ($objects as $n => $_) $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
$pdf .= "trailer << /Size " . (count($objects)+1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="poll-' . $pid . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
