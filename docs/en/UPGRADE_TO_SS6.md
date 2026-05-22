# Upgrade to Silverstripe CMS 6

## Dependencies

⚠️ Update `composer.json` requirements:
- `silverstripe/framework`: `^5` → `^6.0`
- `silverstripe/assets`: `^2` → `^3.0`

## BuildTask API Changes

### Task Configuration

⚠️ **Breaking:** BuildTask configuration properties have changed:

- `private static $segment` → `protected static string $commandName`
- `protected $title` → `protected string $title` (add type hint)
- `protected $description` → `protected static string $description` (make static, add type hint)

### Task Execution

⚠️ **Breaking:** Replace `run(HTTPRequest $request)` with `execute(InputInterface $input, PolyOutput $output): int`

**Before:**
```php
public function run($request)
{
    if ($id = $request->getVar('check')) {
        echo 'Result';
    }
}
```

**After:**
```php
protected function execute(InputInterface $input, PolyOutput $output): int
{
    if ($id = $input->getOption('check')) {
        $output->writeln('Result');
    }
    return Command::SUCCESS;
}
```

Add required imports:
```php
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Command\Command;
use SilverStripe\PolyExecution\PolyOutput;
```

### Input Options

Define console input options via `getOptions()`:
```php
public function getOptions(): array
{
    return [
        new InputOption('check', 'c', InputOption::VALUE_REQUIRED, 'Check if a specific file ID is used'),
    ];
}
```

Add import:
```php
use Symfony\Component\Console\Input\InputOption;
```

## Output Handling

⚠️ **Breaking:** Remove `HTTPRequest` and replace output methods:

- Remove `Director::is_cli()` checks
- Replace `echo` statements with `$output->writeln()` or `$output->write()`
- Remove `PHP_EOL` constants (handled by `writeln()`)
- Pass `PolyOutput $output` parameter through method chains

**Before:**
```php
echo 'Message' . PHP_EOL;
if (!Director::is_cli()) {
    echo 'ERROR: This task can only be run from the command line.' . PHP_EOL;
    return;
}
```

**After:**
```php
$output->writeln('Message');
// CLI-only restrictions are handled by the framework
```

## Calling Tasks from Other Tasks

⚠️ **Breaking:** When invoking tasks programmatically, create console input/output objects:

**Before:**
```php
$task->run(new HTTPRequest('GET', "/dev/tasks/UnusedFileReportBuildTask"));
```

**After:**
```php
$definition = new InputDefinition($task->getOptions());
$input = new ArrayInput([], $definition);
$output = PolyOutput::create(PolyOutput::FORMAT_ANSI);
$task->run($input, $output);
```

Add imports:
```php
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\ArrayInput;
use SilverStripe\PolyExecution\PolyOutput;
```

🔍 **Note:** The diff shows duplicate variable initialization in `UnusedFileReportJob.php` (lines 95-100). Remove the duplication.

## Queued Jobs

Update job implementations to use new task execution pattern (see "Calling Tasks from Other Tasks" above).

Remove imports:
```php
use SilverStripe\Control\HTTPRequest;
```

## Reports

Add `#[Override]` attributes to overridden methods in Report subclasses:
```php
#[Override]
public function title() { }

#[Override]
public function description() { }

#[Override]
public function columns() { }

#[Override]
public function getReportField() { }
```

Add import:
```php
use Override;
```

## Removed Classes

Remove unused imports:
- `SilverStripe\Control\Director`
- `SilverStripe\Control\HTTPRequest`
- `SilverStripe\Versioned\VersionedGridFieldState\VersionedGridFieldState`

## Method Signature Updates

Update method signatures to pass `PolyOutput` through the call chain:
```php
// Before
protected function deleteFile(int $id, int $myCount): bool

// After
protected function deleteFile(int $id, int $myCount, PolyOutput $output): bool
```

Apply to:
- `deleteFile()`
- `deletePhysicalFile()`
- `buildReportTable()`
