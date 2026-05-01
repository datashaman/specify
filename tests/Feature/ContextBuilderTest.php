<?php

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Subtask;
use App\Services\Context\NullContextBuilder;
use App\Services\Context\RecencyContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

function makeGitWorkdir(): string
{
    $dir = sys_get_temp_dir().'/specify-ctx-'.uniqid();
    File::ensureDirectoryExists($dir);
    new Process(['git', '-c', 'init.defaultBranch=main', 'init'], $dir)->mustRun();
    new Process(['git', 'config', 'user.email', 't@t'], $dir)->mustRun();
    new Process(['git', 'config', 'user.name', 't'], $dir)->mustRun();

    return $dir;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/specify-ctx-*') as $path) {
        is_dir($path) && File::deleteDirectory($path);
    }
});

test('NullContextBuilder always returns empty', function () {
    $subtask = Subtask::factory()->create();

    expect((new NullContextBuilder)->build($subtask, sys_get_temp_dir(), null))->toBe('');
});

test('RecencyContextBuilder returns empty when there is no working dir', function () {
    $subtask = Subtask::factory()->create(['description' => 'edit app/Foo.php']);

    expect((new RecencyContextBuilder)->build($subtask, null, null))->toBe('');
});

test('RecencyContextBuilder picks up files mentioned in the description that exist on disk', function () {
    $dir = makeGitWorkdir();
    File::ensureDirectoryExists($dir.'/app');
    File::put($dir.'/app/Exporter.php', "<?php\nclass Exporter {}\n");
    new Process(['git', 'add', 'app/Exporter.php'], $dir)->mustRun();
    new Process(['git', 'commit', '-m', 'feat: add Exporter'], $dir)->mustRun();

    $subtask = Subtask::factory()->create([
        'description' => 'Update app/Exporter.php to support CSV. Also touch nonexistent.txt.',
    ]);

    $brief = (new RecencyContextBuilder)->build($subtask, $dir, null);

    expect($brief)
        ->toContain('<context-brief>')
        ->toContain('## Files the subtask description mentions')
        ->toContain('`app/Exporter.php`')
        ->not->toContain('`nonexistent.txt`')
        ->toContain('## Recently touched')
        ->toContain('feat: add Exporter');
});

test('RecencyContextBuilder surfaces prior failed runs on the same Subtask', function () {
    $subtask = Subtask::factory()->create(['description' => 'no files mentioned here']);
    AgentRun::create([
        'runnable_type' => $subtask->getMorphClass(),
        'runnable_id' => $subtask->getKey(),
        'status' => AgentRunStatus::Failed,
        'error_message' => 'compiler exploded',
        'output' => ['summary' => 'I tried writing a file and got rejected'],
    ]);

    $brief = (new RecencyContextBuilder)->build($subtask, null, null);

    expect($brief)
        ->toContain('## Prior runs on this Subtask that did not complete')
        ->toContain('compiler exploded')
        ->toContain('I tried writing a file and got rejected');
});

test('RecencyContextBuilder is a no-op when there is nothing useful to say', function () {
    $subtask = Subtask::factory()->create(['description' => 'pure prose with no path-like tokens']);

    expect((new RecencyContextBuilder)->build($subtask, null, null))->toBe('');
});

test('RecencyContextBuilder ignores path traversal attempts in the description', function () {
    $dir = makeGitWorkdir();

    $subtask = Subtask::factory()->create([
        'description' => 'malicious mention of ../../etc/passwd should not be surfaced.',
    ]);

    $brief = (new RecencyContextBuilder)->build($subtask, $dir, null);

    expect($brief)->not->toContain('../etc/passwd');
});
