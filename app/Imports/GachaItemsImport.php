<?php

namespace App\Imports;

use App\Models\GachaDetails;
use Maatwebsite\Excel\Concerns\ToCollection;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithStartRow;
class GachaItemsImport implements ToCollection, WithStartRow
{
    private $gacha_id;
    public function __construct($gacha_id)
    {
        $this->gacha_id = $gacha_id;
    }

   

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            if ($row[0] === null) return 0;
            GachaDetails::create([
                'gacha_id'  => $this->gacha_id,
                'item_id'   => $row[0],
                'percent'   => $row[1],
                'guaranteed' => $row[2] == 'SSR' ? '1' : '0', 
                'qty'       => $row[3],
            ]);
        }
    }
}
