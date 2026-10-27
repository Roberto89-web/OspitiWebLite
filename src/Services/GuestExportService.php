<?php

namespace App\Services;

use App\GuestManager;

class GuestExportService {
    /**
     * Generates a fixed-width TXT file of guests.
     * Strictly follows the 168 chars per line format.
     *
     * @param array $guests
     * @return string
     */
    public function generateFixedWidthExport(array $guests): string {
        $lines = [];
        $currentRegId = null;

        foreach ($guests as $guest) {
            $isHead = ($currentRegId !== ($guest['registration_id'] ?? null));
            $currentRegId = $guest['registration_id'] ?? null;

            // Dates formatting: Y-m-d -> d/m/Y
            $arrivalDate = $guest['arrival_date'] ? date('d/m/Y', strtotime($guest['arrival_date'])) : str_repeat(' ', 10);
            $birthDate = $guest['birth_date'] ? date('d/m/Y', strtotime($guest['birth_date'])) : str_repeat(' ', 10);

            // Mandatory Fields (134 chars)
            $line = str_pad($guest['member_type_id'] ?? '', 2, ' ', STR_PAD_RIGHT);
            $line .= str_pad($arrivalDate, 10, ' ', STR_PAD_RIGHT);
            $line .= str_pad($guest['stay_days'] ?? '', 2, ' ', STR_PAD_RIGHT);
            $line .= str_pad($guest['last_name'] ?? '', 50, ' ', STR_PAD_RIGHT);
            $line .= str_pad($guest['first_name'] ?? '', 30, ' ', STR_PAD_RIGHT);
            $line .= str_pad(($guest['gender'] === 'M' ? '1' : ($guest['gender'] === 'F' ? '2' : '')), 1, ' ', STR_PAD_RIGHT);
            $line .= str_pad($birthDate, 10, ' ', STR_PAD_RIGHT);
            $line .= str_pad($guest['birth_city_code'] ?? '', 9, ' ', STR_PAD_RIGHT);
            $line .= str_pad($guest['birth_prov_code'] ?? '', 2, ' ', STR_PAD_RIGHT);
            $line .= str_pad($guest['birth_state_code'] ?? '', 9, ' ', STR_PAD_RIGHT);
            $line .= str_pad($guest['citizenship_state_code'] ?? '', 9, ' ', STR_PAD_RIGHT);

            // Conditional Fields (34 chars)
            if ($isHead) {
                $line .= str_pad($guest['doc_type'] ?? '', 5, ' ', STR_PAD_RIGHT);
                $line .= str_pad($guest['doc_number'] ?? '', 20, ' ', STR_PAD_RIGHT);
                $line .= str_pad($guest['doc_issue_city'] ?? '', 9, ' ', STR_PAD_RIGHT);
            } else {
                $line .= str_repeat(' ', 34);
            }

            $lines[] = $line;
        }

        return implode("\r\n", $lines);
    }
}
