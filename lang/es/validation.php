<?php

return [
    'before_or_equal' => 'El campo :attribute debe ser una fecha anterior o igual a :date.',
    'date' => 'El campo :attribute no es una fecha válida.',
    'enum' => 'El valor de :attribute no es válido.',
    'exists' => 'El :attribute seleccionado no es válido.',
    'gt' => [
        'numeric' => 'El campo :attribute debe ser mayor que :value.',
    ],
    'integer' => 'El campo :attribute debe ser un número entero.',
    'max' => [
        'numeric' => 'El campo :attribute no debe ser mayor que :max.',
        'string' => 'El campo :attribute no debe tener más de :max caracteres.',
    ],
    'min' => [
        'numeric' => 'El campo :attribute debe ser al menos :min.',
    ],
    'numeric' => 'El campo :attribute debe ser un número.',
    'regex' => 'El formato de :attribute no es válido.',
    'required' => 'El campo :attribute es obligatorio.',
    'required_without' => 'El campo :attribute es obligatorio.',
    'string' => 'El campo :attribute debe ser texto.',
    'unique' => 'Ya existe un registro con ese :attribute.',

    'custom' => [
        'barcode' => [
            'regex' => 'El código de barras debe tener de 6 a 14 dígitos.',
            'unique' => 'Ese código de barras ya es de otro artículo.',
        ],
    ],

    'attributes' => [
        'name' => 'nombre',
        'notes' => 'notas',
        'brand' => 'marca',
        'barcode' => 'código de barras',
        'category' => 'categoría',
        'size' => 'contenido',
        'unit' => 'unidad',
        'price' => 'precio',
        'pack_qty' => 'piezas por paquete',
        'observed_on' => 'fecha',
        'product_id' => 'artículo',
        'provider_id' => 'proveedor',
        'price_id' => 'precio',
        'quantity' => 'cantidad',
        'shopped_on' => 'fecha de compra',
        'copy_from' => 'lista a copiar',
        'item_id' => 'artículo de la lista',
        'today' => 'hoy',
    ],
];
