<?php

namespace Tiger\PosBackup\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Tiger\PosBackup\TigerPosBackupServiceProvider;

class ScheduleTest extends TestCase
{
    public function test_schedule_is_registered_with_six_hour_cron_frequency(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $events = $schedule->events();
        $this->assertNotEmpty($events);

        $matchedEvent = null;
        foreach ($events as $event) {
            if (isset($event->command) && str_contains($event->command, 'tigerpos:backup')) {
                $matchedEvent = $event;
                break;
            }
        }

        $this->assertNotNull($matchedEvent, 'tigerpos:backup event was not found in scheduler');
        $this->assertEquals('0 */6 * * *', $matchedEvent->expression);
    }

    public function test_schedule_is_not_registered_when_global_backup_disabled(): void
    {
        // Create fresh application with backup disabled prior to booting
        $_ENV['TIGERPOS_BACKUP_ENABLED'] = 'false';
        putenv('TIGERPOS_BACKUP_ENABLED=false');

        $app = $this->createApplication();

        /** @var Schedule $schedule */
        $schedule = $app->make(Schedule::class);

        $matchedEvent = null;
        foreach ($schedule->events() as $event) {
            if (isset($event->command) && str_contains($event->command, 'tigerpos:backup')) {
                $matchedEvent = $event;
                break;
            }
        }

        putenv('TIGERPOS_BACKUP_ENABLED');
        unset($_ENV['TIGERPOS_BACKUP_ENABLED']);

        $this->assertNull($matchedEvent, 'tigerpos:backup must not be scheduled when enabled is false');
    }

    public function test_schedule_is_not_registered_when_schedule_disabled(): void
    {
        $_ENV['TIGERPOS_BACKUP_SCHEDULE_ENABLED'] = 'false';
        putenv('TIGERPOS_BACKUP_SCHEDULE_ENABLED=false');

        $app = $this->createApplication();

        /** @var Schedule $schedule */
        $schedule = $app->make(Schedule::class);

        $matchedEvent = null;
        foreach ($schedule->events() as $event) {
            if (isset($event->command) && str_contains($event->command, 'tigerpos:backup')) {
                $matchedEvent = $event;
                break;
            }
        }

        putenv('TIGERPOS_BACKUP_SCHEDULE_ENABLED');
        unset($_ENV['TIGERPOS_BACKUP_SCHEDULE_ENABLED']);

        $this->assertNull($matchedEvent, 'tigerpos:backup must not be scheduled when schedule.enabled is false');
    }

    public function test_schedule_registration_is_idempotent_and_prevents_duplicate_events(): void
    {
        $provider = new TigerPosBackupServiceProvider($this->app);
        // Call boot() a second time on the same application container
        $provider->boot();

        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $count = 0;
        foreach ($schedule->events() as $event) {
            if (isset($event->command) && str_contains($event->command, 'tigerpos:backup')) {
                $count++;
            }
        }

        $this->assertEquals(1, $count, 'Duplicate schedule events must not be registered');
    }

    public function test_prune_schedule_is_disabled_by_default(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $matchedEvent = null;
        foreach ($schedule->events() as $event) {
            if (isset($event->command) && str_contains($event->command, 'tigerpos:prune-backups')) {
                $matchedEvent = $event;
                break;
            }
        }

        $this->assertNull($matchedEvent, 'tigerpos:prune-backups must not be scheduled by default');
    }

    public function test_prune_schedule_is_registered_when_opt_in_enabled(): void
    {
        $_ENV['TIGERPOS_BACKUP_PRUNE_ENABLED'] = 'true';
        putenv('TIGERPOS_BACKUP_PRUNE_ENABLED=true');

        $app = $this->createApplication();

        /** @var Schedule $schedule */
        $schedule = $app->make(Schedule::class);

        $matchedEvent = null;
        foreach ($schedule->events() as $event) {
            if (isset($event->command) && str_contains($event->command, 'tigerpos:prune-backups')) {
                $matchedEvent = $event;
                break;
            }
        }

        putenv('TIGERPOS_BACKUP_PRUNE_ENABLED');
        unset($_ENV['TIGERPOS_BACKUP_PRUNE_ENABLED']);

        $this->assertNotNull($matchedEvent, 'tigerpos:prune-backups must be scheduled when prune.enabled is true');
        $this->assertEquals('30 2 * * *', $matchedEvent->expression);
        $this->assertStringContainsString('--execute', $matchedEvent->command);
    }

    public function test_prune_schedule_registration_is_idempotent(): void
    {
        $_ENV['TIGERPOS_BACKUP_PRUNE_ENABLED'] = 'true';
        putenv('TIGERPOS_BACKUP_PRUNE_ENABLED=true');

        $app = $this->createApplication();
        $provider = new TigerPosBackupServiceProvider($app);
        $provider->boot();

        /** @var Schedule $schedule */
        $schedule = $app->make(Schedule::class);

        $count = 0;
        foreach ($schedule->events() as $event) {
            if (isset($event->command) && str_contains($event->command, 'tigerpos:prune-backups')) {
                $count++;
            }
        }

        putenv('TIGERPOS_BACKUP_PRUNE_ENABLED');
        unset($_ENV['TIGERPOS_BACKUP_PRUNE_ENABLED']);

        $this->assertEquals(1, $count, 'Duplicate prune schedule events must not be registered');
    }
}

