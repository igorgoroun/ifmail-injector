#!/usr/bin/env php
<?php
$config = new Config();
$config->setInbound('/opt/ifmail-injector/inb');
$config->temp = '/tmp';
$config->backup_dir = '/opt/ifmail-injector/backup';
$config->unzip_command = '/usr/bin/unzip -Lojq -d';
$config->zip_command = '/usr/bin/zip -q9';
$config->bad_files = array('.','..');
$files = array();

while (false !== ($entry = $config->inbound->read())) {
    if (!in_array($entry,$config->bad_files) && !is_dir($config->inbound->path.'/'.$entry)) {
        $arc = new Archive($config,$entry);
        if ($arc->extractArchive()) {
            $arc->loadPackets();
            $arc->parsePackets();
            $arc->dropFiles();
            $arc->saveUpdated();
            $arc->packUpdated();
            $arc->dropFiles();
            $arc->dropDir();
        }
        //print_r($arc);
    }
}


class Archive {
    public $config;
    public $filename;
    public $filepath;
    public $packets;
    function __construct (Config $config,$file) {
        if (is_file($config->inbound->path.'/'.$file)) {
            $this->config = $config;
            $this->filename = $file;
            $this->filepath = $config->inbound->path;
            $this->packets = array();
        }
    }
    function addPacket (Packet $packet) {
        $this->packets []= $packet;
    }
    function extractArchive() {
        if (mkdir($this->config->temp.'/'.$this->filename)) {
            exec($this->config->unzip_command.' '.$this->config->temp.'/'.$this->filename.' '.$this->filepath.'/'.$this->filename);
            return true;
        } else return false;
    }
    function loadPackets() {
        $tmp = dir($this->config->temp.'/'.$this->filename);
        while (false !== ($entry = $tmp->read())) {
            if (!in_array($entry,$this->config->bad_files) && !is_dir($tmp->path.'/'.$entry)) {
                $pack = new Packet();
                $pack->filename = $entry;
                $handle = fopen($tmp->path.'/'.$entry, "r");
                $pack->source = unpack('C*',fread($handle,filesize($tmp->path.'/'.$entry)));
                //print strlen($pack->source).":".filesize($tmp->path.'/'.$entry);
                $this->packets []= $pack;
            }
        }
    }
    function parsePackets() {
        for ($i=0;$i<count($this->packets);$i++) {
            $packet = &$this->packets[$i];
            $packet->updated = array();
            $b = 1;
            foreach ($packet->source as $byte) {
                // REPLACE to H --------------
                if ($byte == 141) $byte = 72;
                // ---------------------------
                $packet->updated[$b] = str_pad(dechex($byte),2,0,STR_PAD_LEFT);
                $b++;
            }
        }
    }
    function saveUpdated() {
        foreach ($this->packets as $packet) {
            $fn = fopen($this->config->temp.'/'.$this->filename.'/'.$packet->filename,'w');
            fwrite($fn,hex2bin(implode('',$packet->updated)));
            fclose($fn);
        }
    }
    function packUpdated() {
        $filelist = array();
        foreach ($this->packets as $packet) {
            $filelist []= $packet->filename;
        }
        if ($this->backupArc()) {
            exec('cd '.$this->config->temp.'/'.$this->filename.' && '.$this->config->zip_command.' '.$this->filepath.'/'.$this->filename.' '.implode(' ',$filelist));
        }
    }
    function backupArc() {
        if (copy($this->filepath.'/'.$this->filename,$this->config->backup_dir.'/'.date('Y-m-d-H-i-s').'-'.$this->filename)) {
            unlink($this->filepath.'/'.$this->filename);
            return true;
        } else return false;
    }
    function dropFiles() {
        foreach ($this->packets as $packet) {
            unlink($this->config->temp.'/'.$this->filename.'/'.$packet->filename);
        }
    }
    function dropDir() {
        rmdir($this->config->temp.'/'.$this->filename);
    }
}

class Packet {
    public $filename;
    public $source;
    public $codepage;
    public $updated;
}

class Config {
    public $inbound;
    public $temp;
    public $unzip_command;
    public $zip_command;
    public $bad_files;
    public function setInbound($path) {
        if (is_dir($path)) {
            $this->inbound = dir($path);
        } else {
            die('Invalid INBOUND PATH');
        }
    }
}
?>
