<?php

namespace ReachHub\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'             => 'required|string|max:255',
            'description'      => 'nullable|string',
            'channel'          => 'required|string|in:email,whatsapp,sms,push',
            'subject'          => 'nullable|string|max:255',
            'body'             => 'required|string',
            'from_name'        => 'nullable|string|max:255',
            'from_address'     => 'nullable|email',
            'contact_list_ids' => 'nullable|array',
            'contact_list_ids.*' => 'integer|exists:ck_contact_lists,id',
            'template_vars'    => 'nullable|array',
            'metadata'         => 'nullable|array',
            // WhatsApp-specific
            'metadata.whatsapp_template'     => 'nullable|string',
            'metadata.whatsapp_language'     => 'nullable|string',
            'metadata.whatsapp_body_params'  => 'nullable|array',
            'metadata.whatsapp_header_params'=> 'nullable|array',
            // Push-specific
            'metadata.sound'  => 'nullable|string',
            'metadata.image'  => 'nullable|url',
            'metadata.data'   => 'nullable|array',
        ];
    }
}


// ─────────────────────────────────────────────────────────────────────────────

namespace ReachHub\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCampaignRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'             => 'sometimes|string|max:255',
            'description'      => 'nullable|string',
            'channel'          => 'sometimes|string|in:email,whatsapp,sms,push',
            'subject'          => 'nullable|string|max:255',
            'body'             => 'sometimes|string',
            'from_name'        => 'nullable|string|max:255',
            'from_address'     => 'nullable|email',
            'contact_list_ids' => 'nullable|array',
            'contact_list_ids.*' => 'integer|exists:ck_contact_lists,id',
            'template_vars'    => 'nullable|array',
            'metadata'         => 'nullable|array',
        ];
    }
}
