<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Address extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeOfCustomer(Builder $query, Customer $customer)
    {
        return $query->whereCustomerId($customer->id);
    }

    /**
     * Formatear la dirección para el checkout
     */
    public function forCheckout(): array
    {
        return [
            'id' => $this->id,
            'street' => $this->street,
            'number' => $this->number,
            'neighborhood' => $this->neighborhood,
            'city' => $this->city,
            'state' => $this->state,
            'zipcode' => $this->zipcode,
            'additional_references' => $this->additional_references,
            'full_address' => $this->getFullAddressAttribute(),
            'formatted_address' => $this->getFormattedAddressAttribute(),
        ];
    }

    /**
     * Atributo: Dirección completa
     */
    public function getFullAddressAttribute(): string
    {
        $addressParts = [
            $this->street,
            $this->number,
            $this->neighborhood,
            $this->city,
            $this->state,
            $this->zipcode,
        ];

        // Filtrar partes vacías y unir con comas
        return implode(', ', array_filter($addressParts, function ($part) {
            return !empty($part) && trim($part) !== '';
        }));
    }

    /**
     * Atributo: Dirección formateada (para mostrar)
     */
    public function getFormattedAddressAttribute(): string
    {
        $lines = [];
        
        // Línea 1: Calle y número
        if (!empty($this->street) && !empty($this->number)) {
            $lines[] = $this->street . ' ' . $this->number;
        } elseif (!empty($this->street)) {
            $lines[] = $this->street;
        }
        
        // Línea 2: Colonia
        if (!empty($this->neighborhood)) {
            $lines[] = $this->neighborhood;
        }
        
        // Línea 3: Ciudad, Estado, Código Postal
        $cityStateZip = [];
        if (!empty($this->city)) {
            $cityStateZip[] = $this->city;
        }
        if (!empty($this->state)) {
            $cityStateZip[] = $this->state;
        }
        if (!empty($this->zipcode)) {
            $cityStateZip[] = $this->zipcode;
        }
        
        if (!empty($cityStateZip)) {
            $lines[] = implode(', ', $cityStateZip);
        }
        
        return implode("\n", $lines);
    }
}