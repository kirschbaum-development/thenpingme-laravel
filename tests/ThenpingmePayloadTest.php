<?php

namespace Thenpingme\Tests;

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\Assert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Thenpingme\Collections\ScheduledTaskCollection;
use Thenpingme\Facades\Thenpingme;
use Thenpingme\Payload\ScheduledTaskFinishedPayload;
use Thenpingme\Payload\ScheduledTaskSkippedPayload;
use Thenpingme\Payload\ScheduledTaskStartingPayload;
use Thenpingme\Payload\ThenpingmePayload;
use Thenpingme\Payload\ThenpingmeSetupPayload;
use Thenpingme\TaskIdentifier;

class ThenpingmePayloadTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Config::set([
            'app.name' => 'We changed the project name',
            'thenpingme.project_id' => 'abc123',
            'thenpingme.signing_key' => 'super-secret',
        ]);

        request()->server->add(['SERVER_ADDR' => '127.0.0.1']);
    }

    /** @test */
    public function it_generates_a_task_payload()
    {
        $task = $this->app->make(Schedule::class)->command('generate:payload')->description('This is the description');

        tap(ThenpingmePayload::fromTask($task)->toArray(), function ($payload) use ($task) {
            Assert::assertArraySubset([
                'type' => TaskIdentifier::TYPE_COMMAND,
                'expression' => '* * * * *',
                'command' => 'generate:payload',
                'maintenance' => false,
                'without_overlapping' => false,
                'on_one_server' => false,
                'description' => 'This is the description',
                'mutex' => Thenpingme::fingerprintTask($task),
                'filtered' => false,
            ], $payload);
        });
    }

    /** @test */
    public function it_determines_if_a_task_is_filtered()
    {
        $task = $this->app->make(Schedule::class)
            ->command('thenpingme:filtered')
            ->description('This is the description')
            ->when(function () {
                return false;
            });

        tap(ThenpingmePayload::fromTask($task)->toArray(), function ($payload) use ($task) {
            Assert::assertArraySubset([
                'type' => TaskIdentifier::TYPE_COMMAND,
                'expression' => '* * * * *',
                'command' => 'thenpingme:filtered',
                'maintenance' => false,
                'without_overlapping' => false,
                'on_one_server' => false,
                'description' => 'This is the description',
                'mutex' => Thenpingme::fingerprintTask($task),
                'filtered' => true,
            ], $payload);
        });
    }

    /** @test */
    public function it_generates_a_setup_payload()
    {
        $scheduler = $this->app->make(Schedule::class);

        $events = ScheduledTaskCollection::make([
            $scheduler->command('thenpingme:first')->description('This is the first task'),
            $scheduler->command('thenpingme:second')->description('This is the second task'),
        ]);

        tap(ThenpingmeSetupPayload::make($events)->toArray(), function ($payload) use ($events) {
            Assert::assertArraySubset([
                'project' => [
                    'uuid' => 'abc123',
                    'name' => 'We changed the project name',
                    'signing_key' => 'super-secret',
                ],
                'tasks' => [
                    [
                        'type' => TaskIdentifier::TYPE_COMMAND,
                        'expression' => '* * * * *',
                        'command' => 'thenpingme:first',
                        'maintenance' => false,
                        'without_overlapping' => false,
                        'on_one_server' => false,
                        'description' => 'This is the first task',
                        'mutex' => Thenpingme::fingerprintTask($events[0]),
                    ],
                    [
                        'type' => TaskIdentifier::TYPE_COMMAND,
                        'expression' => '* * * * *',
                        'command' => 'thenpingme:second',
                        'maintenance' => false,
                        'without_overlapping' => false,
                        'on_one_server' => false,
                        'description' => 'This is the second task',
                        'mutex' => Thenpingme::fingerprintTask($events[1]),
                    ],
                ],
            ], $payload);
        });
    }

    /** @test */
    public function it_generates_the_correct_payload_for_a_scheduled_task_starting()
    {
        Carbon::setTestNow('2019-10-11 20:58:00', 'UTC');

        $event = new ScheduledTaskStarting(
            $this->app->make(Schedule::class)
                ->command('thenpingme:first')
                ->description('This is the first task')
                ->withoutOverlapping(10)
                ->onOneServer()
        );

        tap(ThenpingmePayload::fromEvent($event), function ($payload) {
            $this->assertInstanceOf(ScheduledTaskStartingPayload::class, $payload);

            tap($payload->toArray(), function ($body) use ($payload) {
                $this->assertEquals($payload->fingerprint(), $body['fingerprint']);
                $this->assertEquals('127.0.0.1', $body['ip']);
                $this->assertEquals('ScheduledTaskStarting', $body['type']);
                $this->assertEquals('2019-10-11T20:58:00+00:00', $body['time']);
                $this->assertEquals('2019-10-11T21:08:00+00:00', $body['expires']);
                $this->assertTrue($body['task']['without_overlapping']);
                $this->assertTrue($body['task']['on_one_server']);
                $this->assertArrayHasKey('memory', $body);
            });
        });
    }

    /** @test */
    public function it_includes_the_release_if_configured_to_do_so()
    {
        config(['thenpingme.release' => 'this is the release']);

        $event = new ScheduledTaskStarting(
            $this->app->make(Schedule::class)
                ->command('thenpingme:first')
                ->description('This is the first task')
                ->withoutOverlapping(10)
                ->onOneServer()
        );

        tap(ThenpingmePayload::fromEvent($event), function ($payload) {
            tap($payload->toArray(), function ($body) {
                $this->assertEquals('this is the release', $body['release']);
                $this->assertEquals('this is the release', $body['task']['release']);
            });
        });
    }

    /** @test */
    public function it_generates_the_correct_payload_for_a_scheduled_task_finished()
    {
        Carbon::setTestNow('2019-10-11 20:58:00', 'UTC');

        $event = new ScheduledTaskFinished(
            $this->app->make(Schedule::class)->command('thenpingme:first')->description('This is the first task'),
            1
        );

        tap(ThenpingmePayload::fromEvent($event), function ($payload) {
            $this->assertInstanceOf(ScheduledTaskFinishedPayload::class, $payload);

            tap($payload->toArray(), function ($body) use ($payload) {
                $this->assertEquals($payload->fingerprint(), $body['fingerprint']);
                $this->assertEquals('127.0.0.1', $body['ip']);
                $this->assertEquals('ScheduledTaskFinished', $body['type']);
                $this->assertEquals('2019-10-11T20:58:00+00:00', $body['time']);
                $this->assertEquals('1', $body['runtime']);
                $this->assertNull($body['exit_code']);
                $this->assertArrayHasKey('memory', $body);
            });
        });
    }

    /** @test */
    public function it_generates_the_correct_payload_for_a_scheduled_task_skipped()
    {
        Carbon::setTestNow('2019-10-11 20:58:00', 'UTC');

        $event = new ScheduledTaskSkipped(
            $this->app->make(Schedule::class)->command('thenpingme:first')->description('This is the first task'),
            1
        );

        tap(ThenpingmePayload::fromEvent($event), function ($payload) {
            $this->assertInstanceOf(ScheduledTaskSkippedPayload::class, $payload);

            tap($payload->toArray(), function ($body) use ($payload) {
                $this->assertEquals($payload->fingerprint(), $body['fingerprint']);
                $this->assertEquals('127.0.0.1', $body['ip']);
                $this->assertEquals('ScheduledTaskSkipped', $body['type']);
                $this->assertEquals('2019-10-11T20:58:00+00:00', $body['time']);
            });
        });
    }
}
