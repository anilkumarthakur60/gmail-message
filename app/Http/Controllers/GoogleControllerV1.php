<?php

namespace App\Http\Controllers;

use App\Models\EmailToken;
use Google\Service\Gmail\Resource\UsersMessages;
use Google_Client;
use Google_Service_Gmail;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
class GoogleControllerV1 extends Controller
{

    private Google_Service_Gmail $googleServiceGmail;

    public function __construct(private readonly Google_Client $client)
    {
        $this->initializeGoogleClient();
        $this->googleServiceGmail = new Google_Service_Gmail($client);
    }

    private function initializeGoogleClient(): void
    {
        $this->client->setClientId(config('gmail.google_client_id'));
        $this->client->setClientSecret(config('gmail.google_client_secret'));
        $this->client->setRedirectUri(config('gmail.google_client_redirect'));
        $this->client->setAccessType('offline');
        $this->client->addScope([
            Google_Service_Gmail::GMAIL_SEND,
            Google_Service_Gmail::GMAIL_READONLY,
            Google_Service_Gmail::GMAIL_COMPOSE,
        ]);
    }

    public function login()
    {
        return redirect()->away($this->client->createAuthUrl());
    }

    /**
     * @throws \Google\Service\Exception
     */
    public function index(Request $request)
    {
        $emailToken = $this->getEmailToken();
        if (!$emailToken) {
            return $this->login();
        }

        $client = $this->initializeClientWithToken($emailToken);
        if ($client->isAccessTokenExpired()) {
            $newToken = $this->refreshAccessToken($client);
            $this->storeTokenToDb($newToken);
        }

        $service = new Google_Service_Gmail($client);
        $gmailLabels = collect($service->users_labels->listUsersLabels('me')->getLabels())->pluck('name')->toArray();
        $param = $this->buildQueryParams($request, $gmailLabels);
        $messages = $service->users_messages->listUsersMessages('me', array_filter($param));
        $filters = $request->only(['search', 'type', 'label', 'client']);

        $messages = $this->processMessages($messages, $service);

        return  [
            'data' => $messages,
            'filters' => $filters,
            'labels' => $gmailLabels,
            'nextPageToken' => $messages->getNextPageToken(),
            'total' => $messages->getResultSizeEstimate(),
        ];
    }

    public function attachmentDownload(Request $request, $messageId, $filename)
    {
        $emailToken = $this->getEmailToken();
        if (!$emailToken) {
            return $this->login();
        }

        $client = $this->initializeClientWithToken($emailToken);
        if ($client->isAccessTokenExpired()) {
            $newToken = $this->refreshAccessToken($client);
            $this->storeTokenToDb($newToken);
        }

        $service = new Google_Service_Gmail($client);
        $attachmentPart = $this->findAttachmentPart($service->users_messages->get('me', $messageId)->getPayload()->getParts(), $filename);

        if ($attachmentPart) {
            $attachment = $service->users_messages_attachments->get('me', $messageId, $attachmentPart['attachmentId']);
            $data = base64_decode(strtr($attachment->getData(), '-_', '+/'));

            return response($data)
                ->header('Content-Type', $attachmentPart['mimeType'])
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        }

        return response('Attachment not found.', 404);
    }

    public function show($threadId, Request $request)
    {
        $filters = $request->only(['search', 'type', 'label', 'client']);
        $emailToken = $this->getEmailToken();
        if (!$emailToken) {
            return $this->login();
        }

        $client = $this->initializeClientWithToken($emailToken);
        if ($client->isAccessTokenExpired()) {
            $newToken = $this->refreshAccessToken($client);
            $this->storeTokenToDb($newToken);
        }

        $service = new Google_Service_Gmail($client);
        $threadEmails = $this->processThreadEmails($service->users_threads->get('me', $threadId));

        $firstEmail = $threadEmails[0]['from'];
        preg_match('/<(.+)>/', $firstEmail, $matches);
        $from = $matches[1] ?? $firstEmail;

        return [
            'data' => $threadEmails,
            'filters' => $filters,
            'from' => $from,
        ];
    }



    private function buildQueryParams(Request $request, array $gmailLabels): array
    {
        return [
            'maxResults' => 10,
            'pageToken' => $request->query('pageToken'),
            'q' => $request->query('search'),
            'labelIds' => $request->filled('label') && in_array($request->query('label'), $gmailLabels) ? $request->query('label') : null,
        ];
    }

    private function processMessages($messages, $service): UsersMessages
    {
        foreach ($messages as $message) {
            $msg = $service->users_messages->get('me', $message->getId());
            $headers = collect($msg->getPayload()?->getHeaders());
            $message->fromName = $this->extractName($headers->firstWhere('name', 'From')?->getValue());
            $message->toName = $this->extractName($headers->firstWhere('name', 'To')?->getValue());
            $message->subject = $headers->firstWhere('name', 'Subject')?->getValue();
            $message->created_at = $this->formatDate($headers->firstWhere('name', 'Date')?->getValue());
            $message->snippet = $msg->getSnippet();
            $message->attachments = $this->extractAttachments($msg->getPayload()->getParts());
            $message->read = !in_array('UNREAD', $msg->getLabelIds());
            $message->starred = in_array('STARRED', $msg->getLabelIds());
            $message->thread_id = $msg->getThreadId();
        }

        return $messages;
    }

    private function extractName(?string $email): string
    {
        preg_match('/(.+?)\s*<.*>/', $email, $matches);
        return $matches[1] ?? $email;
    }

    private function extractAttachments($parts): array
    {
        $attachments = [];
        foreach ($parts as $part) {
            if ($part->getFilename() && $part->getBody()) {
                $attachments[] = [
                    'filename' => $part->getFilename(),
                    'mimeType' => $part->getMimeType(),
                ];
            }
        }
        return $attachments;
    }

    private function formatDate(?string $date): string
    {
        $createdAt = Carbon::parse($date);
        if ($createdAt->isToday()) {
            return $createdAt->format('H:i');
        }
        if ($createdAt->isCurrentMonth() || $createdAt->isCurrentYear()) {
            return $createdAt->format('d M');
        }
        return $createdAt->format('Y-m-d');
    }

    private function processThreadEmails($thread): array
    {
        $threadEmails = [];
        foreach ($thread->getMessages() as $message) {
            $msg = $message->getId();
            $headers = collect($message->getPayload()?->getHeaders());
            $from = $headers->firstWhere('name', 'From')?->getValue();
            $to = $headers->firstWhere('name', 'To')?->getValue();
            $parts = $message->getPayload()->getParts();
            $threadEmails[] = [
                'id' => $msg,
                'fromEmail' => $this->extractEmail($from),
                'toEmail' => $this->extractEmail($to),
                'from' => $from,
                'to' => $to,
                'subject' => $headers->firstWhere('name', 'Subject')?->getValue(),
                'created_at' => $this->formatDate($headers->firstWhere('name', 'Date')?->getValue()),
                'snippet' => $message->getSnippet(),
                'htmlBody' => $this->extractHtmlBody($parts),
                'attachments' => $this->extractAttachments($parts),
                'read' => !in_array('UNREAD', $message->getLabelIds()),
                'starred' => in_array('STARRED', $message->getLabelIds()),
                'thread_id' => $message->getThreadId(),
            ];
        }
        return $threadEmails;
    }

    private function extractEmail(?string $header): string
    {
        preg_match('/<(.+)>/', $header, $matches);
        return $matches[1] ?? $header;
    }

    private function extractHtmlBody($parts): string
    {
        foreach ($parts as $part) {
            if ($part->getMimeType() == 'text/html') {
                return base64_decode(strtr($part->getBody()->getData(), '-_', '+/'));
            }
        }
        return '';
    }


    private function findAttachmentPart($parts, $filename): ?array
    {
        foreach ($parts as $part) {
            if ($part->getFilename() === $filename && $part->getBody()->getAttachmentId()) {
                return [
                    'attachmentId' => $part->getBody()->getAttachmentId(),
                    'mimeType' => $part->getMimeType()
                ];
            }
            if ($part->getParts()) {
                $foundPart = $this->findAttachmentPart($part->getParts(), $filename);
                if ($foundPart) {
                    return $foundPart;
                }
            }
        }
        return null;
    }

    private function storeTokenToDb(array $token): void
    {
        $email = $this->googleServiceGmail->users->getProfile('me')->getEmailAddress();
        $data = [
            'access_token' => $token['access_token'],
            'expires_in' => $token['expires_in'],
            'created' => $token['created'],
            'scope' => $token['scope'],
            'token_type' => $token['token_type'],
        ];
        if (isset($token['refresh_token'])) {
            $data['refresh_token'] = $token['refresh_token'];
        }

        EmailToken::query()->updateOrCreate(['email' => $email], $data);
    }

    private function initializeClientWithToken($token): Google_Client
    {
        $this->client->setAccessToken($token);
        return $this->client;
    }

    private function refreshAccessToken(Google_Client $client): array
    {
        $client->refreshToken($client->getRefreshToken());
        return $client->getAccessToken();
    }

    private function getEmailToken()
    {
        return EmailToken::query()->where('email', auth()->user()->email)->first();
    }
}
