<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Patrol Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Patrol\Api\State;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Patrol\Api\ContractResponse;
use Uhifadhi\Patrol\Api\PatrolApiContext;
use Uhifadhi\Patrol\Api\PatrolApiException;
use Uhifadhi\Patrol\Api\Payload;
use Uhifadhi\Patrol\Service\Api\PhotoSyncService;

/**
 * `POST /api/observations/{uuid}/photos` — API-CONTRACT.md §8.
 *
 * The one multipart endpoint. The fields are read straight off the request
 * rather than deserialized: a multipart body carries an uploaded FILE, which is
 * a filesystem handle and not something a JSON deserializer has any business
 * modelling.
 */
final class UploadPhotoProcessor extends PatrolSyncProcessor
{
    public function __construct(
        private readonly PatrolApiContext $api,
        private readonly PhotoSyncService $photos,
    ) {
    }

    protected function handle(array $uriVariables): Response
    {
        $uuid = $this->api->uriUuid($uriVariables);
        $this->api->requireRecorder($this->api->findObservation($uuid)?->getPatrol()->getArea());

        $observation = $this->api->observation($uuid);
        $this->api->requireWriter($observation->getPatrol());
        $request = $this->api->request();

        $rawUuid = trim((string) $request->request->get('clientUuid'));
        if (!Uuid::isValid($rawUuid)) {
            throw PatrolApiException::invalidPayload('"clientUuid" is required and must be a UUID.', ['field' => 'clientUuid']);
        }
        $clientUuid = Uuid::fromString($rawUuid);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw PatrolApiException::invalidPayload('A photo upload needs a "file" part.', ['field' => 'file']);
        }

        [$position, $accuracyM] = $this->position($request);

        [, $duplicate] = $this->photos->store(
            $observation,
            $clientUuid,
            $file,
            $this->takenAt($request->request->get('takenAt')),
            $position,
            $accuracyM,
        );

        return ContractResponse::ack([$clientUuid->toRfc4122()], $duplicate);
    }

    /**
     * WHERE THE SHUTTER FIRED (§8): `lat`, `lng` and `accuracyM`, as multipart
     * parts. The phone has always sent them; until this method existed they were
     * read by nobody.
     *
     * OMITTED, NEVER ZERO. All three are absent together when the photograph was
     * taken with no fix, so absence is the ordinary case and gets no position —
     * emphatically not `0,0`, which is a real place in the Gulf of Guinea.
     *
     * HALF A PAIR IS A BUG. `lat` without `lng` is not a photograph without a
     * fix, it is something upstream going wrong, and storing "no position" for
     * it would hide the fault behind a plausible answer. Same for an accuracy
     * with nothing to be accurate about.
     *
     * NOTE THE KEY: this endpoint's is `lng` while every JSON position on the
     * API uses `lon`. That is the contract as the app already sends it, and
     * renaming a field the handset ships would break sync to tidy a spelling.
     * The value is handed to the same {@see Payload::geoJsonPoint()} every other
     * position goes through, so the ±90/±180 bounds check and the lon-first
     * ordering are shared rather than re-implemented here.
     *
     * @return array{0: string|null, 1: float|null}
     *
     * @throws PatrolApiException
     */
    private function position(Request $request): array
    {
        $lat = self::decimal($request, 'lat');
        $lng = self::decimal($request, 'lng');
        $accuracyM = self::decimal($request, 'accuracyM');

        if (null === $lat && null === $lng) {
            if (null !== $accuracyM) {
                throw PatrolApiException::invalidPayload('An "accuracyM" describes a fix, and this upload carries none — send "lat" and "lng" with it, or omit all three.', ['field' => 'accuracyM']);
            }

            return [null, null];
        }

        if (null === $lat || null === $lng) {
            throw PatrolApiException::invalidPayload('A photograph\'s position needs both "lat" and "lng", or neither.', ['field' => null === $lat ? 'lat' : 'lng']);
        }

        return [Payload::geoJsonPoint(['lat' => $lat, 'lon' => $lng]), $accuracyM];
    }

    /**
     * One multipart part as a number. Every part arrives as a STRING, so this
     * is where "6.0" becomes 6.0; an empty part counts as absent (a form
     * serializer that writes an empty value for a field it has nothing for is
     * saying "nothing", not "zero"), and anything non-numeric is refused.
     *
     * @throws PatrolApiException
     */
    private static function decimal(Request $request, string $field): ?float
    {
        $raw = $request->request->get($field);

        if (null === $raw || (\is_string($raw) && '' === trim($raw))) {
            return null;
        }

        if (!is_numeric($raw)) {
            throw PatrolApiException::invalidPayload(\sprintf('"%s" must be a number.', $field), ['field' => $field, 'value' => $raw]);
        }

        return (float) $raw;
    }

    /**
     * When the picture was taken, per the phone. Absent is acceptable — a
     * photograph without a timestamp is still evidence — but a malformed one is
     * refused rather than silently dropped.
     *
     * @throws PatrolApiException
     */
    private function takenAt(mixed $raw): ?\DateTimeImmutable
    {
        if (!\is_string($raw) || '' === trim($raw)) {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($raw))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw PatrolApiException::invalidPayload('"takenAt" is not an ISO-8601 timestamp.', ['field' => 'takenAt', 'value' => $raw]);
        }
    }
}
