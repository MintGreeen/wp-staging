<?php
namespace WPStaging\Backend\Modules\Jobs;

// No Direct Access
if (!defined("WPINC"))
{
    die;
}

use WPStaging\WPStaging;

/**
 * Class Scan
 * @package WPStaging\Backend\Modules\Jobs
 */
class Scan extends JobWithCommandLine
{

    /**
     * @var array
     */
    private $directories = array();

    /**
     * Upon class initialization
     */
    protected function initialize()
    {
        // Database Tables
        $this->getTables();

        // Get directories
        $this->directories();
    }

    /**
     * Start Module
     * @return $this
     */
    public function start()
    {
        // Basic Options
        $this->options->root                    = str_replace(array("\\", '/'), DIRECTORY_SEPARATOR, ABSPATH);
        $this->options->existingClones          = json_decode(
            json_encode(get_option("wpstg_existing_clones", array())))
        ;

        // Tables
        $this->options->excludedTables          = array();
        $this->options->clonedTables            = array();

        // Files
        $this->options->totalFiles              = 0;
        $this->options->copiedFiles             = 0;

        // Directories
        $this->options->includedDirectories     = array();
        $this->options->extraDirectories        = array();
        $this->options->directoriesToCopy       = array();
        $this->options->scannedDirectories      = array();
        $this->options->lastScannedDirectory    = array();

        // Job
        $this->options->currentJob              = "database";
        $this->options->currentStep             = 0;
        $this->options->totalSteps              = 0;

        // Save options
        $this->saveOptions();

        return $this;
    }

    /**
     * Format bytes into human readable form
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    public function formatSize($bytes, $precision = 2)
    {
        if ((int) $bytes < 1)
        {
            return '';
        }

        $units  = array('B', "KB", "MB", "GB", "TB");

        $bytes  = (int) $bytes;
        $base   = log($bytes) / log(1000); // 1024 would be for MiB KiB etc
        $pow    = pow(1000, $base - floor($base)); // Same rule for 1000

        return round($pow, $precision) . ' ' . $units[(int) floor($base)];
    }

    /**
     * @param null|string $directories
     * @return string
     */
    public function directoryListing($directories = null)
    {
        if (null == $directories)
        {
            $directories = $this->directories;
        }

        $output = '';
        foreach ($directories as $name => $directory)
        {
            // Need to preserve keys so no array_shift()
            $data = reset($directory);
            unset($directory[key($directory)]);

            $isChecked = (
                empty($this->options->includedDirectories) ||
                in_array($data["path"], $this->options->includedDirectories)
            );

            $output .= "<div class='wpstg-dir'>";
            $output .= "<input type='checkbox' class='wpstg-check-dir'";
            if ($isChecked) $output .= " checked";
            $output .= " name='selectedDirectories[]' value='{$data["path"]}'>";

            $output .= "<a href='#' class='wpstg-expand-dirs";
            if (false === $isChecked) $output .= " disabled";
            $output .= "'>{$name}";
            $output .= "</a>";

            $output .= "<span class='wpstg-size-info'>{$this->formatSize($data["size"])}</span>";

            if (!empty($directory))
            {
                $output .= "<div class='wpstg-dir wpstg-subdir'>";
                $output .= $this->directoryListing($directory);
                $output .= "</div>";
            }

            $output .= "</div>";
        }

        return $output;
    }

    /**
     * Checks if there is enough free disk space to create staging site
     * Returns null when can't run disk_free_space function one way or another
     * @return bool|null
     */
    public function hasFreeDiskSpace()
    {
        if (!function_exists("disk_free_space"))
        {
            return null;
        }

        $freeSpace = @disk_free_space(ABSPATH);

        if (false === $freeSpace)
        {
            return null;
        }

        return ($freeSpace >= $this->getDirectorySize(ABSPATH));
    }

    /**
     * Get Database Tables
     */
    protected function getTables()
    {
        $wpDB = WPStaging::getInstance()->get("wpdb");

        if (strlen($wpDB->prefix) > 0)
        {
            $sql = "SHOW TABLE STATUS LIKE '{$wpDB->prefix}%'";
        }
        else
        {
            $sql = "SHOW TABLE STATUS";
        }

        $tables = $wpDB->get_results($sql);

        $currentTables = array();

        foreach ($tables as $table)
        {
            // Exclude WP Staging Tables
            if (0 === strpos($table->Name, "wpstg"))
            {
                continue;
            }

            $currentTables[] = array(
                "name"  => $table->Name,
                "size"  => ($table->Data_length + $table->Index_length)
            );
        }

        $this->options->tables = json_decode(json_encode($currentTables));
    }

    /**
     * Get directories and main meta data about'em recursively
     */
    protected function directories()
    {
        $directories = new \DirectoryIterator(ABSPATH);

        foreach($directories as $directory)
        {
            // Not a valid directory
            if (false === ($path = $this->getPath($directory)))
            {
                continue;
            }

            $this->handleDirectory($path);

            // Get Sub-directories
            $this->getSubDirectories($directory->getRealPath());
        }

        // Gather Plugins
        $this->getSubDirectories(WP_PLUGIN_DIR);

        // Gather Themes
        $this->getSubDirectories(WP_CONTENT_DIR  . DIRECTORY_SEPARATOR . "themes");

        // Gather Uploads
        $this->getSubDirectories(WP_CONTENT_DIR  . DIRECTORY_SEPARATOR . "uploads");
    }

    /**
     * @param string $path
     */
    protected function getSubDirectories($path)
    {
        $directories = new \DirectoryIterator($path);

        foreach($directories as $directory)
        {
            // Not a valid directory
            if (false === ($path = $this->getPath($directory)))
            {
                continue;
            }

            $this->handleDirectory($path);
        }
    }

    /**
     * Get Path from $directory
     * @param \SplFileInfo $directory
     * @return string|false
     */
    protected function getPath($directory)
    {
        $path = str_replace(ABSPATH, null, $directory->getRealPath());

        // Using strpos() for symbolic links as they could create nasty stuff in nix stuff for directory structures
        if (!$directory->isDir() || strlen($path) < 1 || strpos($directory->getRealPath(), ABSPATH) !== 0)
        {
            return false;
        }

        return $path;
    }

    /**
     * Organizes $this->directories
     * @param string $path
     */
    protected function handleDirectory($path)
    {
        $directoryArray = explode(DIRECTORY_SEPARATOR, $path);
        $total          = count($directoryArray);

        if (count($total) < 1)
        {
            return;
        }

        $total          = $total - 1;
        $currentArray   = &$this->directories;

        for ($i = 0; $i <= $total; $i++)
        {
            if (!isset($currentArray[$directoryArray[$i]]))
            {
                $currentArray[$directoryArray[$i]] = array();
            }

            $currentArray = &$currentArray[$directoryArray[$i]];

            // Attach meta data to the end
            if ($i < $total)
            {
                continue;
            }

            $fullPath   = ABSPATH . $path;
            $size       = $this->getDirectorySize($fullPath);

            $currentArray["metaData"] = array(
                "size"      => $size,
                "path"      => ABSPATH . $path,
            );
        }
    }

    /**
     * Gets size of given directory
     * @param string $path
     * @return int|null
     */
    protected function getDirectorySize($path)
    {
        // TODO check if user allowed it from settings

        // Basics
        $path       = realpath($path);

        // Invalid path
        if (false === $path)
        {
            return null;
        }

        // We can use exec(), you go dude!
        if (true === $this->canUseExec)
        {
            return $this->getDirectorySizeWithExec($path);
        }

        // Well, exec failed try popen()
        if (true === $this->canUsePopen)
        {
            return $this->getDirectorySizeWithPopen($path);
        }

        // Good, old PHP... slow but will get the job done
        return $this->getDirectorySizeWithPHP($path);
    }

    /**
     * Get given directory size using PHP
     * WARNING! This function might cause memory / timeout issues
     * @param string $path
     * @return int
     */
    private function getDirectorySizeWithPHP($path)
    {
        // Iterator
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        $totalBytes = 0;

        // Loop & add file size
        foreach ($iterator as $file)
        {
            try {
                $totalBytes += $file->getSize();
            } // Some invalid symbolik links can cause issues in *nix systems
            catch(\Exception $e) {
                // TODO log
            }
        }

        return $totalBytes;
    }

    /**
     * @param string $path
     * @return int
     */
    private function getDirectorySizeWithExec($path)
    {
        // OS is not supported
        if (!in_array($this->OS, array("WIN", "LIN"), true))
        {
            return 0;
        }

        // WIN OS
        if ("WIN" === $this->OS)
        {
            return $this->getDirectorySizeForWinWithExec($path);
        }

        // *Nix OS
        return $this->getDirectorySizeForNixWithExec($path);
    }

    /**
     * @param string $path
     * @return int
     */
    private function getDirectorySizeForNixWithExec($path)
    {
        exec("du -s {$path}", $output, $return);

        $size = explode("\t", $output[0]);

        if (0 == $return && count($size) == 2)
        {
            return (int) $size[0];
        }

        return 0;
    }

    /**
     * @param string $path
     * @return int
     */
    private function getDirectorySizeForWinWithExec($path)
    {
        exec("diruse {$path}", $output, $return);

        $size = explode("\t", $output[0]);

        if (0 == $return && count($size) >= 4)
        {
            return (int) $size[0];
        }

        return 0;
    }

    /**
     * @param string $path
     * @return int
     */
    private function getDirectorySizeWithPopen($path)
    {
        // OS is not supported
        if (!in_array($this->OS, array("WIN", "LIN"), true))
        {
            return 0;
        }

        // WIN OS
        if ("WIN" === $this->OS)
        {
            return $this->getDirectorySizeForWinWithCOM($path);
        }

        // *Nix OS
        return $this->getDirectorySizeForNixWithPopen($path);
    }

    /**
     * @param string $path
     * @return int
     */
    private function getDirectorySizeForNixWithPopen($path)
    {
        $filePointer= popen("/usr/bin/du -sk {$path}", 'r');

        $size       = fgets($filePointer, 4096);
        $size       = (int) substr($size, 0, strpos($size, "\t"));

        pclose($filePointer);

        return $size;
    }

    /**
     * @param string $path
     * @return int
     */
    private function getDirectorySizeForWinWithCOM($path)
    {
        if (!class_exists("\\COM"))
        {
            return 0;
        }

        $com = new \COM("scripting.filesystemobject");

        $directory = $com->getfolder($path);

        return (int) $directory->size;
    }
}