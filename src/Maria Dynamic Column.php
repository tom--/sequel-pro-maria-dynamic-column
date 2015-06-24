#!/usr/bin/env php
<!---
Copyright (c) 2015, Tom Worster <fsb@thefsb.org>

Permission to use, copy, modify, and/or distribute this software for any purpose with or
without fee is hereby granted, provided that the above copyright notice and this permission
notice appear in all copies.

THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES WITH REGARD TO THIS
SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL
THE AUTHOR BE LIABLE FOR ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY
DAMAGES WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN ACTION OF
CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF OR IN CONNECTION WITH THE USE
OR PERFORMANCE OF THIS SOFTWARE.
--->
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Maria Dynamic Column JSON display</title>
    <meta name="description" content="Displays a Maria Dynamic Column blob decoded to JSON">
    <style>
        code {
            font-family: Consolas, Menlo, "Liberation Mono", Courier, monospace;
            font-size: 100%;
            white-space: pre-wrap;
        }
        .json span.nm {color: #00a;}
        .json span.ky {color: #090;}
        .json span.st {color: #60a;}
        .json span.bo {color: #09b;}
        .json span.nl {color: #c00;}
    </style>
</head>
<body>
<pre><?php
    ini_set('display_errors', 1);

    /**
     * Attempt to read a Maria Dynamic Column value as a JSON document.
     *
     * @param array $pk PK of the row in question in the form [columnName => value, ...]
     * @param string $blobCol Name of the BLOB column to decode
     *
     * @return bool|string The BLOB's decoded JSON string or false on failure
     */
    function jsonValue($pk, $blobCol)
    {
        $from = "FROM `{$_ENV['SP_SELECTED_DATABASE']}`.`{$_ENV['SP_SELECTED_TABLE']}`";

        $where = [];
        foreach ($pk as $col => $val) {
            $where[] = "`$col` = " . var_export($val, true);
        }
        $where = "WHERE " . implode(' AND ', $where);

        if (runQuery("SELECT COLUMN_CHECK(`$blobCol`) $from $where") === '1') {
            $json = runQuery("SELECT COLUMN_JSON(`$blobCol`) $from $where");

            // The JSON in the query output text is pretty ragged w.r.t. the JSON standard.
            $json = strtr($json, ["\n" => '\\n', "\r" => '\\r', "\t" => '\\t', "\f" => '\\f', "\x08" => '\\b']);
            return $json;
        };

        return false;
    }

    /**
     * Run a query using the amazing Sequel Pro dropfile protocol.
     *
     * @param $query
     * @param bool $debug
     *
     * @return bool|string
     */
    function runQuery($query, $debug = false)
    {
        // Files "it is a good programming bahavior" to delete unnecessarily.
        $deleteThese = [
            $queryFile = $_ENV['SP_QUERY_FILE'],
            $resultFile = $_ENV['SP_QUERY_RESULT_FILE'],
            $statusFile = $_ENV['SP_QUERY_RESULT_STATUS_FILE'],
            $statusFile = $_ENV['SP_QUERY_RESULT_META_FILE'],
        ];
        foreach ($deleteThese as $file) {
            @unlink($file);
        }

        // Write the query to its dropfile.
        file_put_contents($queryFile, $query);

        // Send a request to Sequel Pro to run the query.
        exec("open sequelpro://{$_ENV['SP_PROCESS_ID']}@passToDoc/ExecuteQuery/");

        // Wait up to 2 seconds for Sequel Pro to finish and write the status file.
        $n = 0;
        do {
            usleep(100000);
            $n += 1;
            $status = @file_get_contents($statusFile);
        } while ($status === false && $n < 20);

        // On failure, Sequel Pro provides no details about it so simply return false.
        if ($status === '1') {
            return false;
        }

        // Read the result file and dispose of the headers row.
        $result = file_get_contents($resultFile);
        $result = trim(substr($result, strpos($result, "\n")));

        if ($debug) {
            echo ">>> query was: $query\n\n";
            echo ">>> result: " . var_export($result, true) . "\n\n";
        }

        // Practice good programming bahavior every day.
        foreach ($deleteThese as $file) {
            @unlink($file);
        }

        return $result;
    }

    // Display any fatal error as a text tooltip and quit.
    function fatal($msg)
    {
        echo "$msg";
        exit(205);
    }

    // Open the input metadata file.
    $metaFile = $_ENV['SP_BUNDLE_INPUT_TABLE_METADATA'];
    $file = fopen($metaFile, 'r');

    // Search metadata for the PK columns.
    $pkPos = [];
    $n = 0;
    while (!feof($file)) {
        $fields = @fgets($file);
        if ($fields === false) {
            break;
        }

        $fields = explode("\t", $fields);
        if ($fields[5] === '1') {
            $pkPos[] = $n;
        }

        $n += 1;
    }
    if (empty($pkPos)) {
        // This script depends on a PK.
        fatal('cannot file pk');
    }

    // Read from the input the column names and the fields from the first data row.
    $inputFile = $_ENV['SP_BUNDLE_INPUT'];
    $file = fopen($inputFile, 'r');
    $headings = @fgets($file);
    $fields = @fgets($file);
    if (!$headings || !$fields) {
        fatal('bundle input error. select a row');
    }
    $headings = explode("\t", trim($headings));
    $fields = explode("\t", trim($fields));

    // Form the PK of the data row as an array [colName => value, ...].
    $pk = [];
    foreach ($pkPos as $pos) {
        $pk[$headings[$pos]] = $fields[$pos];
    }

    // Search the fields for the first 'BLOB' that successfully decodes as a
    // Maria Dynamic Column.
    $json = false;
    $pos = false;
    foreach ($fields as $pos => $value) {
        if ($value === 'BLOB') {
            $json = jsonValue($pk, $headings[$pos]);
            if ($json !== false) {
                break;
            }
        }
    }
    if ($json === false) {
        fatal('could not decode a dyn col blob');
    }

    // Finished processing. Now display the JSON formatted and colored.
    ?></pre>

<p>SELECT <?= $headings[$pos] ?>
    FROM <?= $_ENV['SP_SELECTED_TABLE'] ?>
    WHERE <?php var_export($pk) ?></p>

<pre><code class="json"></code></pre>

<script>
    var colorJson = function (json, indent) {
        var re;
        if (indent === undefined) {
            indent = 2;
        }
        if (typeof json !== 'string') {
            json = JSON.stringify(json, null, indent);
        }
        re = '("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\\s*:)?';
        re += '|\\b(true|false|null)\\b';
        re += '|-?\\d+(?:\\.\\d*)?(?:[eE][+\\-]?\\d+)?)';
        re = new RegExp(re, 'g');
        return json
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(re, function (match) {
            var cls = 'nm';
            if (/^"/.test(match)) {
                cls = /:$/.test(match) ? 'ky' : 'st';
            } else if (/true|false/.test(match)) {
                cls = 'bo';
            } else if (/null/.test(match)) {
                cls = 'nl';
            }
            return '<span class="' + cls + '">' + match + '</span>';
        });
    };

    document.querySelector('.json').innerHTML = colorJson(<?= $json ?>);
</script>
</body>
</html><?php exit(205);
