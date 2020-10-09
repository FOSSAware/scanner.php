#!/usr/bin/php
<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/*
* scanner.php
*
* Simple PHP-CLI implementation of an OSS scanner against OSSKB.ORG
* Copyright (C) SCANOSS 2018-2020
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 2 of the License, or
* (at your option) any later version.

* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.

* You should have received a copy of the GNU General Public License
* along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

/* Calculate CRC32C value for $word */
function crc32c_of_string($word)
{
	$len = strlen($word);
	$crc = 0xFFFFFFFF;
	for ($i = 0; $i < $len; $i++)
	{
		$crc = $crc ^ ord($word[$i]);
		for ($j = 7; $j >= 0; $j--)
			$crc = ($crc >> 1) ^ (0x82F63B78 & -($crc & 1));
	}
	return $crc ^ 0xFFFFFFFF;
}

/* Convert case to lowercase, and return zero if it isn't a letter or number
Do it fast and independent from the locale configuration (avoid string.h) */
function normalize($Byte)
{
	if ($Byte < "0")  return "";
	if ($Byte > "z")  return "";
	if ($Byte <= "9")  return $Byte;
	if ($Byte >= "a") return $Byte;
	if (($Byte >= "A") && ($Byte <= "Z")) return strtolower($Byte);
	return "";
}

function smaller_hash($window)
{
	$out = 0xFFFFFFFF;
	for ($i = 0; $i < count($window); $i++)
		if ($window[$i] < $out) $out = $window[$i];
	return $out;
}

/* Calculate CRC32C value for an int32 */
function crc32c_of_int32($int32)
{
	$d = array();
	$d[1] = $int32 % 256;
	$d[2] = (($int32 - $d[1]) % 65536) / 256;
	$d[3] = (($int32 - $d[1] - $d[2] * 256) % 16777216) / 65536 ;
	$d[4] = ($int32 - $d[1] - $d[2] * 256 - $d[3] * 65536) / 16777216;

	$crc = 0xFFFFFFFF;
	for ($i = 1; $i <=4; $i++)
	{
		$crc = $crc ^ $d[$i];
		for ($j = 7; $j >= 0; $j--)
			$crc = ($crc >> 1) ^ (0x82F63B78 & -($crc & 1));
	}
	return $crc ^ 0xFFFFFFFF;
}

/* Return WFP fingerprints for $filename */
function calc_wfp($filename)
{
	/* Read file contents */
	$src = file_get_contents($filename);

	/* Gram/Window configuration. Modifying these values would require rehashing the KB	*/
	$GRAM = 30;
	$WINDOW = 64;
	$LIMIT = 10000;

	$line = 1;
	$last_line = 0;
	$counter = 0;
	$hash = 0xFFFFFFFF;
	$last_hash = 0;
	$last = 0;
	$gram = "";
	$gram_ptr = 0;
	$window = array();
	$window_ptr = 0;

	/* Add line entry */
	$out = "file=" . md5($src) . "," . strlen($src) . ",$filename";

	/* Process one byte at a time */
	$src_len = strlen($src);
	for ($i = 0; $i < $src_len; $i++)
	{
		if ($src[$i] == "\n") $line++;

		$byte = normalize($src[$i]);
		if (!$byte) continue;

		/* Add byte to the gram */
		$gram[$gram_ptr++] = $byte;

		/* Got a full gram? */
		if ($gram_ptr >= $GRAM)
		{

			/* Add fingerprint to the window */
			$window[$window_ptr++] = crc32c_of_string($gram);

			/* Got a full window? */
			if ($window_ptr >= $WINDOW)
			{

				/* Add hash */
				$hash = smaller_hash($window);
				if ($hash != $last_hash)
				{
					$last_hash = $hash;
					$hash = crc32c_of_int32($hash);
					if ($line != $last_line)
					{
						$out .= "\n$line=".dechex($hash);
						$last_line = $line;
					}
					else $out .= ",".dechex($hash);

					if ($counter++ >= $LIMIT) break;
				}

				/* Shift window */
				array_shift($window);
				$window_ptr = $WINDOW - 1;
				$window[$window_ptr] = 0xFFFFFFFF;
			}

			/* Shift gram */
			$gram = substr($gram, 1);
			$gram_ptr = $GRAM - 1;
		}
	}
	$out .= "\n";
	return $out;
}

function osskb_query($url, $wfp)
{
	$boundary = "---------------------" . md5(rand());
	$data = "--{$boundary}\r\n";
	$data .= "Content-Disposition: form-data; name=\"file\"; filename=\"file.wfp\"\r\n";
	$data .= "application/octet-stream\r\n\r\n";
	$data .= $wfp . "\r\n";
	$data .= "--{$boundary}--\r\n\r\n";
	$params = array('http' => array('method' => 'POST', 'header' => 'Content-Type: multipart/form-data; boundary=' . $boundary, 'content' => $data));
	$ctx = stream_context_create($params);
	$fp = fopen($url, 'rb', false, $ctx);
	if ($fp) {
		$response = @stream_get_contents($fp);
		fclose($fp);
		if ($response !== false) return $response;
	}
	return "";
}

/* Verify that parameter is present */
if ($argc != 2)
{
	print "Missing path\n";
	exit(0);
}

/* Verify that parameter is a valid file */
$path = $argv[1];
if (!is_file($path))
{
	print "The path specified is not a file\n";
	exit(0);
}

/* Calculate wfp fingerprints */
$wfp = calc_wfp($path);

/* Post fingerprints to OSSKB API */
print osskb_query("https://osskb.org/api/scan/direct", $wfp);
?>