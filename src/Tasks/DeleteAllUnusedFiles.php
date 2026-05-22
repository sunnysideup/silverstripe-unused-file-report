<?php

namespace RobIngram\SilverStripe\UnusedFileReport\Tasks;

use Symfony\Component\Console\Input\InputInterface;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Command\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;
use SilverStripe\Core\Config\Config;
use SilverStripe\Assets\File;
use SilverStripe\Core\Environment;
use RobIngram\SilverStripe\UnusedFileReport\Model\UnusedFileReportDB;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Control\Controller;
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
    protected static string $commandName = 'delete-all-unused-files';

    /**
     * {@inheritDoc}
     * @var string
     */
    protected string $title = 'Delete all unused files';

    /**
     * {@inheritDoc}
     * @var string
     */
    protected static string $description = 'All the files that currently listed in the unused file report will be deleted.';

    private static bool $skip_deleting_folders = true;

    private static bool $skip_deleting_images = false;

    private static bool $skip_deleting_non_images = true;

    private static bool $skip_deleting_folders_physical_only = false;

    private static bool $skip_deleting_images_physical_only = false;

    private static bool $skip_deleting_non_images_physical_only = false;

    private static bool $skip_deleting_all_files_physical_only = false;

    protected bool $skipDeletingFolders;

    protected bool $skipDeletingImages;

    protected bool $skipDeletingNonImages;

    protected bool $skipDeletingFoldersPhysicalOnly;

    protected bool $skipDeletingImagesPhysicalOnly;

    protected bool $skipDeletingNonImagesPhysicalOnly;

    protected bool $skipDeletingAllFilesPhysicalOnly;

    protected int $countOfFiles = 0;


    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        Environment::increaseMemoryLimitTo(-1);
        Environment::increaseTimeLimitTo(-1);
        $output->writeln('');
        $output->writeln('======================');
        $output->writeln('Delete all unused files');
        $output->writeln('======================');

        $this->skipDeletingFolders = Config::inst()->get(self::class, 'skip_deleting_folders');
        $this->skipDeletingImages = Config::inst()->get(self::class, 'skip_deleting_images');
        $this->skipDeletingNonImages = Config::inst()->get(self::class, 'skip_deleting_non_images');
        $this->skipDeletingFoldersPhysicalOnly = Config::inst()->get(self::class, 'skip_deleting_folders_physical_only');
        $this->skipDeletingImagesPhysicalOnly = Config::inst()->get(self::class, 'skip_deleting_images_physical_only');
        $this->skipDeletingNonImagesPhysicalOnly = Config::inst()->get(self::class, 'skip_deleting_non_images_physical_only');
        $this->skipDeletingAllFilesPhysicalOnly = Config::inst()->get(self::class, 'skip_deleting_all_files_physical_only');
        $definition = new InputDefinition(UnusedFileReportBuildTask::create()->getOptions());
        $inputForSubtask = new ArrayInput([], $definition);
        $outputForSubtask = PolyOutput::create(PolyOutput::FORMAT_ANSI);
        UnusedFileReportBuildTask::create()->run($inputForSubtask, $outputForSubtask);
        $list = UnusedFileReportDB::get()->columnUnique('FileID');
        $this->countOfFiles = count($list);
        if ($list) {
            $myCount = 0;
            foreach ($list as $id) {
                $myCount++;
                $this->deleteFile($id, $myCount, $output);
            }
        } else {
            $output->writeln('No files to delete.');
        }

        $output->writeln('======================');
        $output->writeln('');
        return Command::SUCCESS;
    }


    protected function deleteFile(int $id, int $myCount, PolyOutput $output): bool
    {
        $file = File::get()->byID($id);

        if ($file) {
            $output->write('Looking at file: ' . $myCount . ' / ' . $this->countOfFiles . ': '  . $file->getFilename());
            if ($this->skipDeletingFolders && $file instanceof Folder) {
                $output->writeln('... Skipping folder: ' . $file->getFilename());
                return true;
            }

            if ($this->skipDeletingImages && $file instanceof Image) {
                $output->writeln('... Skipping image: ' . $file->getFilename());
                return true;
            }

            if ($this->skipDeletingNonImages) {
                if (!($file instanceof Image)) {
                    $output->writeln('... Skipping non-image file: ' . $file->getFilename());
                    return true;
                } elseif ($file->getExtension() === 'svg') {
                    $output->writeln('... Skipping SVG file: ' . $file->getFilename());
                    return true;
                } elseif ($file->getExtension() === 'pdf') {
                    $output->writeln('... Skipping PDF file: ' . $file->getFilename());
                    return true;
                }
            }

            $file->deleteFromStage(Versioned::DRAFT);
            $file->deleteFromStage(Versioned::LIVE);
            DB::query('DELETE FROM "File" WHERE "ID" = ' . $id . ' LIMIT 1');
            DB::query('DELETE FROM "File_Live" WHERE "ID" = ' . $id . ' LIMIT 1');
            $output->writeln('... Deleted');
            if ($this->deletePhysicalFile($file, $output)) {
                DB::query('DELETE FROM "UnusedFileReportDB" WHERE "FileID" = ' . $id . ' LIMIT 1');
                return true;
            } else {
                return false;
            }
        } else {
            DB::query('DELETE FROM "UnusedFileReportDB" WHERE "FileID" = ' . $id . ' LIMIT 1');
            $output->writeln('ERROR: Could not find DB file to delete, ID is: ' . $id);
        }

        return false;
    }

    protected function deletePhysicalFile($file, PolyOutput $output): bool
    {
        if ($this->skipDeletingAllFilesPhysicalOnly) {
            return true;
        }

        $fileName = $file->getFilename();
        if ($fileName) {
            $path = Controller::join_links(ASSETS_PATH, $fileName);
            if (file_exists($path)) {
                $output->write('ERROR: Also having to delete physical file: ' . $path);
                if ($this->skipDeletingFoldersPhysicalOnly && $file instanceof Folder) {
                    return true;
                }

                if ($this->skipDeletingImagesPhysicalOnly && $file instanceof Image) {
                    return true;
                }

                if ($this->skipDeletingNonImagesPhysicalOnly && !($file instanceof Image) && !($file instanceof Folder)) {
                    return true;
                }

                if ($this->deleteDirectoryOrFile($path)) {
                    $output->writeln('... Deleted physical file: ' . $path);
                } else {
                    $output->writeln('... Deletion did not work successfully: ' . $path);
                }

                if (file_exists($path)) {
                    $output->writeln('ERROR: Could not delete file: ' . $path);
                    return false;
                }
            } else {
                return true;
            }
        } else {
            $output->writeln('ERROR: Could not find filename for File with ID: ' . $file->ID);
        }

        return false;
    }

    protected function deleteDirectoryOrFile(string $path): bool
    {

        if (! is_dir($path)) {
            return unlink($path);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        return rmdir($path);
    }
}
