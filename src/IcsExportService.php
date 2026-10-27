<?php

namespace App;

class IcsExportService {
    /**
     * Generates an iCalendar (.ics) string from a list of guests.
     *
     * @param array $guests List of guests from GuestManager::getAllGuests()
     * @return string The formatted ICS content
     */
    public function generate(array $guests): string {
        $lines = [];

        // 1. Header
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:-//OspitiWebServer//Calendar 1.0//EN';

        foreach ($guests as $guest) {
            $arrival = $guest['DataArrivo'];
            $departure = $guest['DataPartenza'];
            $nights = $guest['GiorniSoggiorno'];
            $firstName = $guest['NomeCapofamiglia'];
            $lastName = $guest['CognomeCapofamiglia'];
            $id = $guest['id'];

            // Format dates for DTSTART and DTEND (YYYYMMDD)
            $dtStart = date('Ymd', strtotime($arrival));
            $dtEnd = date('Ymd', strtotime($departure));

            // Format dates for DESCRIPTION (DD Month YYYY)
            $arrivalFormatted = date('d F Y', strtotime($arrival));
            $departureFormatted = date('d F Y', strtotime($departure));

            // 2. Event Block
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = "DTSTART;VALUE=DATE:{$dtStart}";
            $lines[] = "DTEND;VALUE=DATE:{$dtEnd}";
            $lines[] = 'SUMMARY:Reserved';
            $lines[] = "DESCRIPTION:CHECKIN: {$arrivalFormatted} - CHECKOUT: {$departureFormatted} - Nights: {$nights} - Guest: {$firstName} {$lastName}";
            $lines[] = "UID:guest-{$id}@ospiti.local";
            $lines[] = 'END:VEVENT';
        }

        // 3. Footer
        $lines[] = 'END:VCALENDAR';

        // Join with CRLF (\r\n) as per RFC 5545
        return implode("\r\n", $lines) . "\r\n";
    }
}
