<?php
/**
 * Zip File Generator Helper
 * Supports native ZipArchive with automatic pure-PHP fallback (Zero Dependencies).
 */

class ZipHelper {
    private $files = []; // [ ['name' => ..., 'data' => ...] ]

    public function addFromString($name, $data) {
        $this->files[] = [
            'name' => str_replace('\\', '/', $name),
            'data' => $data
        ];
    }

    /**
     * Send the ZIP archive directly to the client browser as a download
     */
    public function download($filename) {
        // Clear any previous output buffering
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $this->build();
        exit;
    }

    /**
     * Build the raw binary ZIP data
     */
    public function build() {
        if (class_exists('ZipArchive')) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'cb_zip_');
            $zip = new ZipArchive();
            if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                foreach ($this->files as $f) {
                    $zip->addFromString($f['name'], $f['data']);
                }
                $zip->close();
                $content = file_get_contents($tmpFile);
                @unlink($tmpFile);
                if ($content !== false && strlen($content) > 0) {
                    return $content;
                }
            }
        }

        // Pure PHP Zip Implementation (Fallback)
        $datasec = [];
        $ctrlDir = [];
        $dtime = dechex($this->unix2DosTime(time()));
        $hexdtime = '\x' . $dtime[6] . $dtime[7]
                  . '\x' . $dtime[4] . $dtime[5]
                  . '\x' . $dtime[2] . $dtime[3]
                  . '\x' . $dtime[0] . $dtime[1];
        eval('$hexdtime = "' . $hexdtime . '";');

        $offset = 0;

        foreach ($this->files as $f) {
            $name = $f['name'];
            $data = $f['data'];

            $unc_len = strlen($data);
            $crc     = crc32($data);

            if (function_exists('gzdeflate')) {
                $zdata = gzdeflate($data);
                $zdata = substr($zdata, 0, strlen($zdata));
                $c_len = strlen($zdata);
                $method = "\x08\x00"; // Deflated
            } else {
                $zdata = $data;
                $c_len = $unc_len;
                $method = "\x00\x00"; // Stored
            }

            // Local file header
            $fr  = "\x50\x4b\x03\x04";
            $fr .= "\x14\x00";            // ver needed to extract
            $fr .= "\x00\x00";            // gen purpose bit flag
            $fr .= $method;               // compression method
            $fr .= $hexdtime;             // last mod time and date
            $fr .= pack('V', $crc);       // crc32
            $fr .= pack('V', $c_len);     // compressed filesize
            $fr .= pack('V', $unc_len);   // uncompressed filesize
            $fr .= pack('v', strlen($name)); // length of filename
            $fr .= pack('v', 0);          // extra field length
            $fr .= $name;
            $fr .= $zdata;

            $datasec[] = $fr;

            // Central directory entry
            $cdrec  = "\x50\x4b\x01\x02";
            $cdrec .= "\x00\x00";            // version made by
            $cdrec .= "\x14\x00";            // version needed to extract
            $cdrec .= "\x00\x00";            // gen purpose bit flag
            $cdrec .= $method;               // compression method
            $cdrec .= $hexdtime;             // last mod time & date
            $cdrec .= pack('V', $crc);       // crc32
            $cdrec .= pack('V', $c_len);     // compressed filesize
            $cdrec .= pack('V', $unc_len);   // uncompressed filesize
            $cdrec .= pack('v', strlen($name)); // length of filename
            $cdrec .= pack('v', 0);          // extra field length
            $cdrec .= pack('v', 0);          // file comment length
            $cdrec .= pack('v', 0);          // disk number start
            $cdrec .= pack('v', 0);          // internal file attributes
            $cdrec .= pack('V', 32);         // external file attributes - 'archive' bit set
            $cdrec .= pack('V', $offset);    // relative offset of local header
            $cdrec .= $name;

            $ctrlDir[] = $cdrec;
            $offset += strlen($fr);
        }

        $dataStream = implode('', $datasec);
        $ctrldirStream = implode('', $ctrlDir);

        // End of central directory record
        $eofCd = "\x50\x4b\x05\x06"
               . "\x00\x00"                  // number of this disk
               . "\x00\x00"                  // number of the disk with the start of the central directory
               . pack('v', count($ctrlDir))  // total number of entries in the central dir on this disk
               . pack('v', count($ctrlDir))  // total number of entries in the central dir
               . pack('V', strlen($ctrldirStream)) // size of the central directory
               . pack('V', strlen($dataStream))    // offset of start of central directory with respect to the starting disk number
               . "\x00\x00";                 // zipfile comment length

        return $dataStream . $ctrldirStream . $eofCd;
    }

    private function unix2DosTime($unixtime = 0) {
        $timearray = ($unixtime == 0) ? getdate() : getdate($unixtime);
        if ($timearray['year'] < 1980) {
            $timearray['year']    = 1980;
            $timearray['mon']     = 1;
            $timearray['mday']    = 1;
            $timearray['hours']   = 0;
            $timearray['minutes'] = 0;
            $timearray['seconds'] = 0;
        }
        return (($timearray['year'] - 1980) << 25)
            | ($timearray['mon'] << 21)
            | ($timearray['mday'] << 16)
            | ($timearray['hours'] << 11)
            | ($timearray['minutes'] << 5)
            | ($timearray['seconds'] >> 1);
    }
}
