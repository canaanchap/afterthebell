<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

require __DIR__ . '/Parsedown.php';

$ROOT = realpath(__DIR__ . '/..');
$CONTENT = $ROOT . '/content';
$TEMPLATES = $ROOT . '/templates';
$ASSETS = $ROOT . '/assets';
$OUT = $ROOT . '/site';

require $TEMPLATES . '/header.php';
require $TEMPLATES . '/footer.php';

$md = new Parsedown();
$md->setSafeMode(false); // allow inline HTML in markdown (useful for “Fabien-style” control)

// --------------------
// helpers
// --------------------
function rrmdir(string $dir): void {
  if (!is_dir($dir)) return;
  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );
  foreach ($it as $item) {
    $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
  }
  rmdir($dir);
}

function copy_dir(string $src, string $dst): void {
  if (!is_dir($src)) return;
  @mkdir($dst, 0777, true);
  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );
  foreach ($it as $item) {
    $target = $dst . '/' . substr($item->getPathname(), strlen($src) + 1);
    if ($item->isDir()) @mkdir($target, 0777, true);
    else copy($item->getPathname(), $target);
  }
}

// Front-matter (super minimal):
// Title: My Post Title
// Date: 2026-01-28
function parse_meta(string $raw): array {
  $lines = preg_split("/\R/", $raw);
  $title = '';
  $date = '';
  $start = 0;

  if (isset($lines[0]) && str_starts_with($lines[0], "Title: ")) { $title = trim(substr($lines[0], 7)); $start++; }
  if (isset($lines[1]) && str_starts_with($lines[1], "Date: "))  { $date  = trim(substr($lines[1], 6)); $start++; }

  $body = implode("\n", array_slice($lines, $start));
  return [$title, $date, $body];
}

// Footnote syntax you’ll write in Markdown:
//
// In text:  ... like this[^dreamcast] ...
//
// Somewhere later (usually bottom):
// [^dreamcast]: https://example.com | A useful link label
//
// This generates Fabien-style superscripts + a References table.
function extract_footnotes(string $mdText): array {
  $refs = [];

  // capture definition lines
  $mdText = preg_replace_callback(
    '/^\[\^([a-zA-Z0-9_-]+)\]:\s*(\S+)\s*\|\s*(.+)$/m',
    function($m) use (&$refs) {
      $id = $m[1];
      $url = $m[2];
      $label = trim($m[3]);
      $refs[$id] = ['url' => $url, 'label' => $label];
      return ''; // remove from markdown body
    },
    $mdText
  );

  // find inline markers in order of appearance
  $order = [];
  $mdText = preg_replace_callback(
    '/\[\^([a-zA-Z0-9_-]+)\]/',
    function($m) use (&$order) {
      $order[] = $m[1];
      // placeholder token, replaced later (after we number them)
      return "%%FOOTNOTE:" . $m[1] . "%%";
    },
    $mdText
  );

  // assign numbers by first-seen order (unique)
  $seen = [];
  $num = 0;
  $map = []; // id -> n
  foreach ($order as $id) {
    if (isset($seen[$id])) continue;
    $seen[$id] = true;
    $num++;
    $map[$id] = $num;
  }

  // replace placeholders with superscripts
  foreach ($map as $id => $n) {
    $mdText = str_replace(
      "%%FOOTNOTE:$id%%",
      "<a name=\"back_$n\" style=\"text-decoration:none;\" href=\"#footnote_$n\"><sup>[$n]</sup></a>",
      $mdText
    );
  }

  // build references table HTML (Fabien-ish)
  $refsHtml = "";
  if (count($map) > 0) {
    $refsHtml .= "<style type='text/css'>td.ref { padding-bottom: 0ch; width:0;}</style>";
    $refsHtml .= "<div class='heading'>References</div><hr/><p id='paperbox' style='text-align:left;'>";
    $refsHtml .= "<table><tbody style='vertical-align: top;'>";
    foreach ($map as $id => $n) {
      if (!isset($refs[$id])) continue; // marker used but no definition
      $url = htmlspecialchars($refs[$id]['url'], ENT_QUOTES);
      $label = htmlspecialchars($refs[$id]['label'], ENT_QUOTES);
      $refsHtml .= "<tr>";
      $refsHtml .= "<td class='ref' style='width:1ch;'><a name=\"footnote_$n\"></a><a href=\"#back_$n\">^</a></td>";
      $refsHtml .= "<td class='ref' style='width:4ch;'> [ $n]</td>";
      $refsHtml .= "<td class='ref' style='width:100%;text-align:left;'><a href=\"$url\">$label</a></td>";
      $refsHtml .= "</tr>";
    }
    $refsHtml .= "</tbody></table></p>";
  }

  return [$mdText, $refsHtml];
}

function render_page(Parsedown $md, string $srcPath, string $dstPath): array {
  [$title, $date, $bodyMd] = parse_meta(file_get_contents($srcPath));
  if ($title === '') $title = basename($srcPath, '.md');

  [$bodyMd, $refsHtml] = extract_footnotes($bodyMd);
  $bodyHtml = $md->text($bodyMd) . $refsHtml;

  @mkdir(dirname($dstPath), 0777, true);

  ob_start();
  // your header.php should define genheader($title, $date)
  genheader($title, $date);
  echo $bodyHtml;
  include __DIR__ . '/../templates/footer.php';
  $final = ob_get_clean();

  file_put_contents($dstPath, $final);
  return [$title, $date];
}

// --------------------
// build
// --------------------
rrmdir($OUT);
mkdir($OUT, 0777, true);

// copy assets (fonts/images/etc.)
copy_dir($ASSETS . '/font', $OUT . '/font');
copy_dir($ASSETS . '/img', $OUT . '/img');

// Build posts
$postsDir = $CONTENT . '/posts';
$posts = glob($postsDir . '/*.md');
$indexItems = [];

foreach ($posts as $p) {
  $slug = basename($p, '.md');
  [$t, $d] = render_page($md, $p, $OUT . "posts/$slug/index.html");
  $indexItems[] = ['slug' => $slug, 'title' => $t, 'date' => $d];
}

// newest first (by Date line; missing dates sort last)
usort($indexItems, function($a, $b) {
  return strcmp($b['date'] ?? '', $a['date'] ?? '');
});

// Build home page from content/index.md + auto list
$homePath = $CONTENT . '/index.md';
$homeMd = file_exists($homePath) ? file_get_contents($homePath) : "Title: Home\nDate: \n\n";
[$homeTitle, $homeDate, $homeBody] = parse_meta($homeMd);

$listHtml = "\n<div class='heading'>Writing</div><hr/>\n<ul>\n";
foreach ($indexItems as $it) {
  $date = $it['date'] ? htmlspecialchars($it['date'], ENT_QUOTES) . " — " : "";
  $title = htmlspecialchars($it['title'], ENT_QUOTES);
  $slug = htmlspecialchars($it['slug'], ENT_QUOTES);
$listHtml .= "<li>{$date}<a href=\"posts/{$slug}/\">{$title}</a></li>\n";
}
$listHtml .= "</ul>\n";
$homeBody .= "\n" . $listHtml;

$tmpHome = $ROOT . '/build/__home_tmp.md';
file_put_contents($tmpHome, "Title: $homeTitle\nDate: $homeDate\n\n" . $homeBody);
render_page($md, $tmpHome, $OUT . "/index.html");
unlink($tmpHome);

// OLD:
// echo "Built " . count($posts) . " posts.\n";
fwrite(STDERR, "Built " . count($posts) . " posts.\n");