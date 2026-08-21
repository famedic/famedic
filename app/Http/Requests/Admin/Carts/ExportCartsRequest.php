<?php

namespace App\Http\Requests\Admin\Carts;

use Illuminate\Foundation\Http\FormRequest;

class ExportCartsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->administrator->hasPermissionTo('view carts');
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'in:pharmacy,lab'],
            'display_status' => ['nullable', 'in:active,abandoned,completed'],
            'operational_filter' => ['nullable', 'in:appointment_pending,appointment_confirmed_pending_payment,callback_requested'],
            'operational_bucket' => ['nullable', 'in:no_progress,attention,payment,appointment,contact'],
            'payment_status' => ['nullable', 'in:pending,approved,declined,error'],
            'checkout_stage' => ['nullable', 'in:no_progress,patient,address,appointment,payment,confirmation,completed'],
            'appointment_filter' => ['nullable', 'in:none,pending,confirmed,confirmed_without_payment'],
            'contact_filter' => ['nullable', 'in:callback_requested,phone_call_intent'],
            'customer_segment' => ['nullable', 'in:new,existing,recurrent'],
            'brand' => ['nullable', 'in:olab,swisslab,liacsa,azteca,jenner,unknown'],
            'amount_range' => ['nullable', 'in:lt_1000,1000_2000,2000_5000,gt_5000'],
            'inactivity_range' => ['nullable', 'in:lt_1h,1_3h,3_24h,1_3d,gt_3d'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
