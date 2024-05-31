<?php

namespace App\Jobs;

use App\Models\Media;
use App\Models\User;
use Exception;
use Google_Service_Gmail_Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Plank\Mediable\Exceptions\MediaUrlException;
use Throwable;

class SendEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public $sender,
        public $receiver,
        public $subject,
        public $body,
        public $thread_id,
        public User $user,
        public Google_Service_Gmail $service,
        public array $mediaIds = [],
        public $reply_to = null,
        public $cc = null,
        public $bcc = null,
    ) {
    }

    /**
     * @throws MediaUrlException
     * @throws Throwable
     */
    public function handle(): void
    {
        $boundary = uniqid(rand(), true);

        // Create the headers for the email
        $headers = [];
        $headers[] = "From: ".$this->receiver;
        $headers[] = "To: ".$this->sender;
        $headers[] = "Subject: ".$this->subject;
        if ($this->cc) {
            $headers[] = "Cc: ".$this->cc;
//            $headers[] = "Cc: " . $this->receiver;
        }
        if ($this->bcc) {
            $headers[] = "Bcc: ".$this->bcc;
        }
        if ($this->reply_to) {
            $headers[] = "Reply-To: ".$this->reply_to;
        }
        $headers[] = "Content-Type: multipart/alternative; boundary=\"$boundary\"";

        // Create the email body
        $body = [];

        // Plain text part
        $body[] = "--$boundary";
        $body[] = "Content-Type: text/plain; charset=UTF-8";
        $body[] = "Content-Transfer-Encoding: 7bit";
        $body[] = "";
        $body[] = strip_tags($this->body);

        // HTML part
        $htmlBody = $this->getHtmlBody();
        $body[] = "--$boundary";
        $body[] = "Content-Type: text/html; charset=UTF-8";
        $body[] = "Content-Transfer-Encoding: 7bit";
        $body[] = "";
        $body[] = $htmlBody;

        // Attach media if any
        foreach($this->mediaIds as $mediaId) {
            $media = Media::query()->find($mediaId);
            if ($media) {
                $filePath = $media->getUrl();
                $fileContent = file_get_contents($filePath);
                $fileMimeType = mime_content_type($filePath);
                $fileName = basename($filePath);

                $body[] = "--$boundary";
                $body[] = "Content-Type: $fileMimeType; name=\"$fileName\"";
                $body[] = "Content-Disposition: attachment; filename=\"$fileName\"";
                $body[] = "Content-Transfer-Encoding: base64";
                $body[] = "";
                $body[] = rtrim(chunk_split(base64_encode($fileContent)));
            }
        }

        // End the email body with boundary
        $body[] = "--$boundary--";

        // Join headers and body
        $rawMessage = implode("\r\n", array_merge($headers, ["", implode("\r\n", $body)]));
        $rawMessage = base64_encode($rawMessage);
        $rawMessage = str_replace(['+', '/', '='], ['-', '_', ''], $rawMessage); // base64url encoding

        $message = new Google_Service_Gmail_Message();
        $message->setRaw($rawMessage);

        // Ensure the email is sent from the correct account
        try {
            $this->service->users_messages->send('me', $message);
        } catch (Exception $e) {
            Log::error('Email could not be sent: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * @throws Throwable
     */
    private function getHtmlBody(): string
    {

        return view('email', ['email' => $this->sender])->render();
    }
}
