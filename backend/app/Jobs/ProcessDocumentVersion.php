<?php

namespace App\Jobs;

use App\Models\ResourceVersion;
use App\Services\DocumentProcessingService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ProcessDocumentVersion — queued job to extract text and chunk a PDF resource version.
 *
 * This job is dispatched after a resource version is successfully created.
 * It uses the DocumentProcessingService to extract text, detect chapters/headings,
 * and create semantic chunks for AI-powered book understanding.
 */
class ProcessDocumentVersion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The resource version to process.
     */
    protected int $resourceVersionId;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public $backoff = [60, 300, 900]; // 1min, 5min, 15min

    /**
     * Create a new job instance.
     */
    public function __construct(int $resourceVersionId)
    {
        $this->resourceVersionId = $resourceVersionId;
    }

    /**
     * Execute the job.
     */
    public function handle(DocumentProcessingService $processingService): void
    {
        $resourceVersion = ResourceVersion::find($this->resourceVersionId);

        if (! $resourceVersion) {
            // Resource version was deleted, skip processing
            return;
        }

        // Only process PDF files
        if ($resourceVersion->media_type !== 'pdf') {
            return;
        }

        try {
            $processingService->processResourceVersion($resourceVersion);
        } catch (Exception $e) {
            // Log the error but don't fail the job immediately
            // The DocumentProcessingService already marks the record as failed
            // and stores error metadata, so we can inspect failures later
            \Log::error('Document processing failed', [
                'resource_version_id' => $this->resourceVersionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to let the queue handle retries
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Exception $exception): void
    {
        \Log::error('Document processing job failed permanently', [
            'resource_version_id' => $this->resourceVersionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
