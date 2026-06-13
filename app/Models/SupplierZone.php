<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

#[Fillable([
   'supplier_id',
    'zone_id',
])]

class SupplierZone extends Model
{
     protected function casts(): array
    {
        return [
        'supplier_id' => 'integer',
        'zone_id'     => 'integer',
        ];
    }

     public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
 
    public function zone()
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }
}
