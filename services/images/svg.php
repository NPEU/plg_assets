<?php
// This script adds width/height attributes to SVG files.
// IE11 doesn't scale things properly without it, and it doesn't appear to harm anything else.
// When view as a standalone file, the SVG will be of a fixed size (not responsive) but I haven't
// found this to be a problem when the CSS is in play (as that makes it responsive).

$path       = str_replace('?' . $_SERVER['QUERY_STRING'], '', $_SERVER['REQUEST_URI']);
$pathinfo   = pathinfo($path);

$svg = file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/' . $path);

if (!empty($_SERVER['JTV2'])) {
    preg_match('/viewBox="([^"]+)"/', $svg, $matches);
    $viewbox = explode(' ', $matches[1]);

    $svg = str_replace($matches[0], $matches[0] . ' preserveAspectRatio="xMidYMid meet" width="' . $viewbox[2] . 'px" height="' . $viewbox[3] . 'px"', $svg);
}

header('Content-Type: image/svg+xml');
echo $svg;
