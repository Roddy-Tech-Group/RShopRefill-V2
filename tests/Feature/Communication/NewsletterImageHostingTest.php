<?php

namespace Tests\Feature\Communication;

use App\Domain\Communication\Mail\Marketing\NewsletterBroadcastMail;
use App\Jobs\SendNewsletterBroadcastJob;
use App\Models\Admin;
use App\Models\NewsletterSubscriber;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Campaign images are hosted on our own web root under public/assets/newsletter
 * and referenced by absolute URL from the email. Two things make that URL
 * load-bearing in a way a normal CMS image isn't: it is fetched by mail clients
 * long after the send, and it is baked into messages we can no longer edit. So
 * the file must land on disk at pick time (not send time) and must survive the
 * admin detaching it from a campaign.
 */
class NewsletterImageHostingTest extends TestCase
{
    use RefreshDatabase;

    /** Files this test wrote into public/assets, cleaned up afterwards. */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $relative) {
            File::delete(public_path('assets/'.$relative));
        }

        parent::tearDown();
    }

    protected function adminLogin(): self
    {
        $this->seed(AdminSeeder::class);
        $this->actingAs(Admin::first(), 'admin');

        return $this;
    }

    private function composerWithImage(string $filename = 'campaign.png'): Testable
    {
        $component = Volt::test('admin.newsletter')
            ->call('openCompose')
            ->set('subject', 'June Update')
            ->set('imageUpload', UploadedFile::fake()->create($filename, 256, 'image/png'))
            ->assertHasNoErrors();

        $this->written[] = $component->get('imagePath');

        return $component;
    }

    public function test_picking_an_image_hosts_it_immediately_under_public_assets(): void
    {
        $this->adminLogin();

        $component = $this->composerWithImage();
        $path = $component->get('imagePath');

        $this->assertStringStartsWith('newsletter/', $path);
        $this->assertFileExists(public_path('assets/'.$path));

        // The admin must get a full URL - a mail client has no page to resolve
        // a relative path against.
        $url = $component->instance()->imageUrl;
        $this->assertStringStartsWith('http', $url);
        $this->assertStringContainsString('/assets/'.$path, $url);

        // ...and it has to be reachable from the composer, not just in state.
        $component->assertSee($url)->assertSee('Copy URL');
    }

    public function test_the_temporary_upload_is_released_once_the_file_is_hosted(): void
    {
        $this->adminLogin();

        $this->assertNull(
            $this->composerWithImage()->get('imageUpload'),
            'The Livewire temp file should be dropped once the copy lands in public/assets.'
        );
    }

    public function test_broadcast_carries_the_hosted_url_to_the_queued_job(): void
    {
        $this->adminLogin();
        Queue::fake();
        NewsletterSubscriber::create(['email' => 'reader@example.test', 'status' => 'active']);

        $component = $this->composerWithImage();
        $expected = $component->instance()->imageUrl;

        $component->set('body', 'Here is what is new.')->call('broadcast');

        Queue::assertPushed(
            SendNewsletterBroadcastJob::class,
            fn (SendNewsletterBroadcastJob $job) => $job->imageUrl === $expected
        );
    }

    public function test_detaching_an_image_leaves_the_file_hosted(): void
    {
        $this->adminLogin();

        $component = $this->composerWithImage();
        $path = $component->get('imagePath');

        $component->call('detachImage');

        $this->assertSame('', $component->get('imagePath'));
        $this->assertSame('', $component->instance()->imageUrl);
        // An email already sent with this URL must never start 404ing.
        $this->assertFileExists(public_path('assets/'.$path));
    }

    public function test_a_non_latin_subject_still_produces_a_usable_filename(): void
    {
        $this->adminLogin();

        $component = Volt::test('admin.newsletter')
            ->call('openCompose')
            ->set('subject', 'ニュースレター')
            ->set('imageUpload', UploadedFile::fake()->create('campaign.png', 128, 'image/png'))
            ->assertHasNoErrors();

        $path = $component->get('imagePath');
        $this->written[] = $path;

        $this->assertStringStartsWith('newsletter/newsletter-', $path);
        $this->assertFileExists(public_path('assets/'.$path));
    }

    public function test_non_images_and_oversized_files_are_rejected(): void
    {
        $this->adminLogin();

        Volt::test('admin.newsletter')
            ->call('openCompose')
            ->set('imageUpload', UploadedFile::fake()->create('rates.pdf', 64, 'application/pdf'))
            ->assertHasErrors('imageUpload');

        Volt::test('admin.newsletter')
            ->call('openCompose')
            ->set('imageUpload', UploadedFile::fake()->create('huge.png', 6144, 'image/png'))
            ->assertHasErrors('imageUpload');
    }

    public function test_email_renders_the_banner_only_when_a_campaign_has_one(): void
    {
        $withImage = (new NewsletterBroadcastMail(
            subjectLine: 'June Update',
            bodyContent: 'Hello there.',
            isHtml: false,
            imageUrl: 'https://rshoprefills.test/assets/newsletter/june-abcd1234.png',
        ))->render();

        $this->assertStringContainsString(
            'src="https://rshoprefills.test/assets/newsletter/june-abcd1234.png"',
            $withImage
        );

        $without = (new NewsletterBroadcastMail(
            subjectLine: 'June Update',
            bodyContent: 'Hello there.',
        ))->render();

        $this->assertStringNotContainsString('/assets/newsletter/', $without);
    }
}
