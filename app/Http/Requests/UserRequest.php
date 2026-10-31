<?php

namespace App\Http\Requests;

use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Http\FormRequest;

class UserRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    /** One clear message for a password that is too weak (the rule would otherwise give one short message per missing part). */
    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            $errors = $v->errors();
            if (! $errors->has('password')) {
                return;
            }
            $all = $errors->get('password');
            $weak = array_filter($all, fn ($m) => stripos($m, 'confirm') === false);
            if (! $weak) {
                return;
            }
            $confirm = array_filter($all, fn ($m) => stripos($m, 'confirm') !== false);
            $errors->forget('password');
            $errors->add('password', \App\Support\PasswordPolicy::failMessage());
            foreach ($confirm as $m) {
                $errors->add('password', $m);
            }
        });
    }

    public function rules()
    {
        return [
            'phone'        => ['nullable', 'string', 'max:50', new \App\Rules\TzMobile],
            'roles'        => 'array|min:1',
            'name'         => 'required|max:50',
            'edit_all'     => 'nullable|boolean',
            'view_all'     => 'nullable|boolean',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'warehouses'   => 'nullable|array',
            'warehouses.*' => 'exists:warehouses,id',
            'username'     => 'required|unique:users,username,' . optional($this->route('user'))->id,
            'email'        => 'required|email|unique:users,email,' . optional($this->route('user'))->id,
            'password'     => optional($this->route('user'))->id ? ['nullable', 'confirmed', \App\Support\PasswordPolicy::rule()] : ['required', \App\Support\PasswordPolicy::rule()],
        ];
    }

    public function validated($key = null, $default = null)
    {
        $data = $this->validator->validated();

        if (filled($data['phone'] ?? null)) {
            $data['phone'] = \App\Support\TzPhone::normalize($data['phone']); // 0674020223 is saved as +255674020223
        }

        if ($this->input('password')) {
            $data['password'] = Hash::make($this->input('password'));
        } else {
            unset($data['password']);
        }

        // if ($this->file('photo')) {
        //     $data['photo_path'] = $this->file('photo')->store('users');
        // }

        return $data;
    }
}
