<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class AppointmentShowRequest extends FormRequest
{
    public function authorize()
    {
        return true; 
    }

    public function rules()
    {
        return [
            'appointment_id' => 'required|integer|exists:appointments,id',
        ];
    }

    // Merge route parameters with the request data
    public function validationData()
    {
        return array_merge($this->request->all(), $this->route()->parameters());
    }

    protected function failedValidation(Validator $validator)
    {
        // Throw a custom validation error response when validation fails
        throw new HttpResponseException(
            validation_error_response($validator->errors()->toArray())
        );
    }
}
