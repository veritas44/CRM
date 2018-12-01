<?php

namespace ChurchCRM\Backup
{
  use ChurchCRM\dto\SystemURLs;
  use ChurchCRM\FileSystemUtils;
  use ChurchCRM\Utils\LoggerUtils;
  use ChurchCRM\SQLUtils;
  use Exception;
  use Ifsnop\Mysqldump\Mysqldump;
  use Propel\Runtime\Propel;
  use PDO;
  use PharData;
  use RecursiveIteratorIterator;
  use RecursiveDirectoryIterator;
  use SplFileInfo;
  use ChurchCRM\Utils\ExecutionTime;

  abstract class BackupType
  {
      const GZSQL = 0;
      const SQL = 2;
      const FullBackup = 3;
  }

  class JobBase
  {
     /**
       *
       * @var BackupType 
       */
      protected $BackupType;
   
      protected function CreateEmptyTempFolder()
      { 
          // both backup and restore operations require a clean temporary working folder.  Create it.
          $TempFolder = SystemURLs::getDocumentRoot() . "/tmp_attach/ChurchCRMBackups";
          LoggerUtils::getAppLogger()->debug("Creating temp folder at ". $TempFolder);
          FileSystemUtils::recursiveRemoveDirectory($TempFolder, false);
          mkdir($TempFolder, 0750, true);
          LoggerUtils::getAppLogger()->debug("Temp folder created");
          return $TempFolder;
      }
  }
  
  class BackupJob extends JobBase
  {
      private $BackupFileBaseName;
    
      /**
       *
       * @var SplFileInfo
       */
      private $BackupFile;
      /**
       *
       * @var String
       */
      private $TempFolder;
      /**
       *
       * @var Boolean
       */
      private $IncludeExtraneousFiles;
      /**
       *
       * @var String
       */
      public $BackupDownloadFileName;
      /**
       *
       * @param String $BaseName
       * @param BackupType $BackupType
       * @param Boolean $IncludeExtraneousFiles
       */
      public function __construct($BaseName, $BackupType, $IncludeExtraneousFiles)
      {
          $this->BackupType = $BackupType;
          $this->TempFolder =  $this->CreateEmptyTempFolder();
          $this->BackupFileBaseName = $this->TempFolder .'/'.$BaseName;
          $this->IncludeExtraneousFiles = $IncludeExtraneousFiles;
          LoggerUtils::getAppLogger()->debug(
                  "Backup job created; ready to execute: Type: '" .
                  $this->BackupType .
                  "' Temp Folder: '" .
                  $this->TempFolder .
                  "' BaseName: '" . $this->BackupFileBaseName.
                  "' Include extra files: '". ($this->IncludeExtraneousFiles ? 'true':'false') ."'");
      }

      public function CopyToWebDAV($Endpoint, $Username, $Password)
      {
          LoggerUtils::getAppLogger()->info("Beginning to copy backup to: " . $Endpoint);
          try {
              $fh = fopen($this->BackupFile->getPathname(), 'r');
              $remoteUrl = $Endpoint.urlencode($this->BackupFile->getFilename());
              LoggerUtils::getAppLogger()->debug("Full remote URL: " .$remoteUrl);
              $credentials = $Username.":".$Password;
              $ch = curl_init($remoteUrl);
              curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
              curl_setopt($ch, CURLOPT_USERPWD, $credentials);
              curl_setopt($ch, CURLOPT_PUT, true);
              curl_setopt($ch, CURLOPT_INFILE, $fh);
              curl_setopt($ch, CURLOPT_INFILESIZE, $this->BackupFile->getSize());
              LoggerUtils::getAppLogger()->debug("Beginning to send file");
              $time = new \ChurchCRM\Utils\ExecutionTime();
              $result = curl_exec($ch);
              fclose($fh);
              LoggerUtils::getAppLogger()->debug("File send complete.  Took: " . $time->getMiliseconds() . "ms");
          } catch (\Exception $e) {
              LoggerUtils::getAppLogger()->err("Error copying backup: " . $e);
          }
          LoggerUtils::getAppLogger()->info("Backup copy completed.  Curl result: " . $result);
          return $result;
      }
    
      private function CaptureSQLFile(\SplFileInfo $SqlFilePath)
      {
          global $sSERVERNAME, $sDATABASE, $sUSER, $sPASSWORD;
          LoggerUtils::getAppLogger()->debug("Beginning to backup datbase to: " . $SqlFilePath->getPathname());
          try {
              $dump = new Mysqldump('mysql:host=' . $sSERVERNAME . ';dbname=' . $sDATABASE, $sUSER, $sPASSWORD, ['add-drop-table' => true]);
              $dump->start($SqlFilePath->getPathname());
              LoggerUtils::getAppLogger()->debug("Finisehd backing up datbase to " . $SqlFilePath->getPathname());
          } catch (\Exception $e) {
              $message = "Failed to backup database to: " . $SqlFilePath->getPathname(). " Exception: " . $e;
              LoggerUtils::getAppLogger()->error($message);
              throw new Exception($message, 500);
          }
      }
    
      private function ShouldBackupImageFile(SplFileInfo $ImageFile)
      {
          $isExtraneousFile = strpos($ImageFile->getFileName(), "-initials") != false ||
        strpos($ImageFile->getFileName(), "-remote") != false ||
        strpos($ImageFile->getPathName(), "thumbnails") != false;
      
          return $ImageFile->isFile() && !(!$this->IncludeExtraneousFiles && $isExtraneousFile); //todo: figure out this logic
      }
    
      private function CreateFullArchive()
      {
          $imagesAddedToArchive = array();
          $this->BackupFile = new \SplFileInfo($this->BackupFileBaseName.".tar");
          $phar = new PharData($this->BackupFile->getPathname());
          LoggerUtils::getAppLogger()->debug("Archive opened at: ".$this->BackupFile->getPathname());
          $phar->startBuffering();
   
          $SqlFile =  new \SplFileInfo($this->TempFolder."/".'ChurchCRM-Database.sql');
          $this->CaptureSQLFile($SqlFile);
          $phar->addFile($SqlFile, 'ChurchCRM-Database.sql');
          LoggerUtils::getAppLogger()->debug("Database added to archive");
          $imageFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SystemURLs::getImagesRoot()));
          foreach ($imageFiles as $imageFile) {
              if ($this->ShouldBackupImageFile($imageFile)) {
                  $localName = substr(str_replace(SystemURLs::getDocumentRoot(), '', $imageFile->getRealPath()), 1);
                  $phar->addFile($imageFile->getRealPath(), $localName);
                  array_push($imagesAddedToArchive, $imageFile->getRealPath());
              }
          }
          LoggerUtils::getAppLogger()->debug("Images files added to archive: ". join(";", $imagesAddedToArchive));
          $phar->stopBuffering();
          LoggerUtils::getAppLogger()->debug("Finished creating archive.  Beginning to compress");
          $phar->compress(\Phar::GZ);
          LoggerUtils::getAppLogger()->debug("Archive compressed; should now be a .gz file");
          unset($phar);
          unlink($this->BackupFile->getPathname());
          LoggerUtils::getAppLogger()->debug("Initial .tar archive deleted: " . $this->BackupFile->getPathname());
          $this->BackupFile = new \SplFileInfo($this->BackupFileBaseName.".tar.gz");
          LoggerUtils::getAppLogger()->debug("New backup file: " .  $this->BackupFile);
          unlink($SqlFile);
          LoggerUtils::getAppLogger()->debug("Temp Database backup deleted: " . $SqlFile);
      }
    
      private function CreateGZSql()
      {
          $SqlFile =  new \SplFileInfo($this->TempFolder."/".'ChurchCRM-Database.sql');
          $this->CaptureSQLFile($SqlFile);
          $this->BackupFile = new \SplFileInfo($this->BackupFileBaseName.'.sql.gz');
          $gzf = gzopen($this->BackupFile->getPathname(), 'w6');
          gzwrite($gzf, file_get_contents($SqlFile->getPathname()));
          gzclose($gzf);
          unlink($SqlFile->getPathname());
      }
      public function Execute()
      {
          $time = new \ChurchCRM\Utils\ExecutionTime();
          LoggerUtils::getAppLogger()->info("Beginning backup job. Type: " . $this->BackupType . ". BaseName: " . $this->BackupFileBaseName);
          if ($this->BackupType == BackupType::FullBackup) {
              $this->CreateFullArchive();
          } elseif ($this->BackupType == BackupType::SQL) {
              $this->BackupFile = new \SplFileInfo($this->BackupFileBaseName.".sql");
              $this->CaptureSQLFile($this->BackupFile);
          } elseif ($this->BackupType == BackupType::GZSQL) {
              $this->CreateGZSql();
          }
          $time->End();
          $percentExecutionTime = (($time->getMiliseconds()/1000)/ini_get('max_execution_time'))*100;
          LoggerUtils::getAppLogger()->addInfo("Completed backup job.  Took : " . $time->getMiliseconds()."ms. ".$percentExecutionTime."% of max_execution_time");
          if ($percentExecutionTime > 80) {
              // if the backup took more than 80% of the max_execution_time, then write a warning to the log
              LoggerUtils::getAppLogger()->addWarning("Backup task took more than 80% of max_execution_time (".ini_get('max_execution_time').").  Consider increasing this time to avoid a failure");
          }
          $this->BackupDownloadFileName  = $this->BackupFile->getFilename();
          return true;
      }
  }
  
  class RestoreJob extends JobBase
  {
      /**
       *
       * @var SplFileInfo
       */
      private $RestoreFile;
      
      
      private function IsIncomingFileFailed() {
        // Not actually sure what this is supposed to do, but it was working before??
        return $_SERVER['REQUEST_METHOD'] == 'POST' && empty($_POST) && empty($_FILES) && $_SERVER['CONTENT_LENGTH'] > 0;
      }
      
      public function __construct()
      {
        LoggerUtils::getAppLogger()->info("Beginning to process incoming archvie for restoration");
        if ($this->IsIncomingFileFailed()) {
          $message = "The selected file exceeds this servers maximum upload size of: " . SystemService::getMaxUploadFileSize();
          LoggerUtils::getAppLogger()->error($message);
          throw new \Exception($message, 500);
        }
        $rawUploadedFile = $_FILES['restoreFile'];
        $tempDirectory = $this->CreateEmptyTempFolder();
        $this->RestoreFile = new \SplFileInfo($tempDirectory."/" . $rawUploadedFile['name']);
        LoggerUtils::getAppLogger()->debug("Moving ".$rawUploadedFile['tmp_name']. " to ". $this->RestoreFile);
        move_uploaded_file($rawUploadedFile['tmp_name'], $this->RestoreFile);
        LoggerUtils::getAppLogger()->debug("File move complete");
        $this->DiscoverBackupType();
        LoggerUtils::getAppLogger()->debug("Detected backup type:".  $this->RestoreFile->getExtension(). ": " . $this->BackupType);
        LoggerUtils::getAppLogger()->info("Restore job created; ready to execute");
      }
      private function DiscoverBackupType()
      {
         switch($this->RestoreFile->getExtension()) {
          case "gz":
            $basename = $this->RestoreFile->getBasename();
            if (substr($basename, strlen($basename)-6,6) == "tar.gz"){
              $this->BackupType = BackupType::FullBackup;
            }
            else if (substr($basename, strlen($basename)-6,6) == "sql.gz"){
              $this->BackupType = BackupType::GZSQL;
            }
            break;
          case "sql":
            $this->BackupType = BackupType::SQL;
            break;
        }
      }
      
      private function RestoreSQLBackup() {
        $connection = Propel::getConnection();
        LoggerUtils::getAppLogger()->debug("Restoring SQL file from: ".$this->RestoreFile);
        SQLUtils::sqlImport($this->RestoreFile, $connection);
        LoggerUtils::getAppLogger()->debug("Finished restoring SQL table");
      }
    
      public function Execute()
      {
        LoggerUtils::getAppLogger()->info("Executing restore job");
        switch ($this->BackupType) {
          case BackupType::SQL:
            $this->RestoreSQLBackup();
            break;
        }

        LoggerUtils::getAppLogger()->info("Finished executing restore job");
        
      }
  }


  class BackupDownloader
  {
      public static function DownloadBackup($filename)
      {
          $path = SystemURLs::getDocumentRoot() . "/tmp_attach/ChurchCRMBackups/$filename";
          LoggerUtils::getAppLogger()->info("Download requested for :" . $path);
          if (file_exists($path)) {
              if ($fd = fopen($path, 'r')) {
                  $fsize = filesize($path);
                  $path_parts = pathinfo($path);
                  $ext = strtolower($path_parts['extension']);
                  switch ($ext) {
                    case 'gz':
                        header('Content-type: application/x-gzip');
                        header('Content-Disposition: attachment; filename="' . $path_parts['basename'] . '"');
                        break;
                    case 'tar.gz':
                        header('Content-type: application/x-gzip');
                        header('Content-Disposition: attachment; filename="' . $path_parts['basename'] . '"');
                        break;
                    case 'sql':
                        header('Content-type: text/plain');
                        header('Content-Disposition: attachment; filename="' . $path_parts['basename'] . '"');
                        break;
                    case 'gpg':
                        header('Content-type: application/pgp-encrypted');
                        header('Content-Disposition: attachment; filename="' . $path_parts['basename'] . '"');
                        break;
                    case 'zip':
                        header('Content-type: application/zip');
                        header('Content-Disposition: attachment; filename="' . $path_parts['basename'] . '"');
                        break;
                    // add more headers for other content types here
                    default:
                        header('Content-type: application/octet-stream');
                        header('Content-Disposition: filename="' . $path_parts['basename'] . '"');
                        break;
                }
                  header("Content-length: $fsize");
                  header('Cache-control: private'); //use this to open files directly
                  LoggerUtils::getAppLogger()->debug("Headers sent. sending backup file contents");
                  while (!feof($fd)) {
                      $buffer = fread($fd, 2048);
                      echo $buffer;
                  }
                  LoggerUtils::getAppLogger()->debug("Backup file contents sent");
              }
              fclose($fd);
              FileSystemUtils::recursiveRemoveDirectory(SystemURLs::getDocumentRoot() . '/tmp_attach/', true);
              LoggerUtils::getAppLogger()->debug("Removed backup file from server filesystem");
          } else {
              $message = "Requested download does not exist: " . $path;
              LoggerUtils::getAppLogger()->err($message);
              throw new \Exception($message, 500);
          }
      }
  }
  
  
}
