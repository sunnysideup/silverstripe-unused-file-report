<?php

namespace RobIngram\SilverStripe\UnusedFileReport\Tasks;

use Exception;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLInsert;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Core\Config\Config;
use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataObject;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Manifest\ClassLoader;
use SilverStripe\Control\Director;
use RobIngram\SilverStripe\UnusedFileReport\Model\UnusedFileReportDB;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Versioned\Versioned;

/**
 * A task that collects data on unused files
 */
class DeleteAllUnusedFiles extends BuildTask
{
    /**
     * {@inheritDoc}
     * @var string
     */
    private static $segment = 'delete-all-unused-files';

    /**
     * {@inheritDoc}
     * @var string
     */
    protected $title = 'Delete all unused files';

    /**
     * {@inheritDoc}
     * @var string
     */
    protected $description = 'All the files that currently listed in the unused file report will be deleted.';


    /**
     * {@inheritDoc}
     * @param  HTTPRequest $request
     */
    public function run($request)
    {
        Environment::increaseMemoryLimitTo(-1);
        Environment::increaseTimeLimitTo(-1);
        if (! Director::is_cli()) {
            echo 'This task can only be run from the command line.' . PHP_EOL;
            return;
        }
        $list = UnusedFileReportDB::get()->columnUnique('FileID');
        if ($list) {
            foreach ($list as $id) {
                $this->deleteFile($id);
            }
        } else {
            echo 'No files to delete.' . PHP_EOL;
        }
    }


    protected function deleteFile($id)
    {
        $file = File::get()->byID($id);
        if ($file) {
            echo 'Deleting file: ' . $file->getFilename() . PHP_EOL;
            $fileName = $file->getFilename();

            try {
                //$file->deleteFile();
            } catch (Exception $exception) {
                echo 'Caught exception: ' . $exception->getMessage();
            }
            $file->deleteFromStage(Versioned::DRAFT);
            $file->deleteFromStage(Versioned::LIVE);
            DB::query('DELETE FROM "File" WHERE "ID" = ' . $id . ' LIMIT 1');
            DB::query('DELETE FROM "File_Live" WHERE "ID" = ' . $id . ' LIMIT 1');
            $fullName = Controller::join_links(ASSETS_PATH, $fileName);
            if (file_exists($fullName)) {
                echo 'Deleting physical file : ' . $fullName . PHP_EOL;
                unlink($fullName);
                if (file_exists($fullName)) {
                    user_error('Could not delete file...' . $fullName);
                }
            }
        } else {
            user_error(PHP_EOL . 'ERROR: could not find file to delete ' . PHP_EOL);
        }
    }
}
