<?php

// Keep essential daily pages visible; supporting operations remain available by direct routes.
return [
    'purchases' => ['orders', 'quotations', 'foreign-purchases', 'local-purchases', 'goods-receipts', 'purchase-returns'],
    'inventory' => ['materials', 'products', 'stock', 'warehouses', 'transfers', 'counts'],
    'manufacturing' => ['orders', 'boms', 'plans', 'work-orders', 'output'],
    'laboratory' => ['samples', 'tests', 'incoming', 'finished'],
    'sales' => ['invoices', 'quotations', 'orders', 'pricing', 'delivery-notes', 'returns'],
    'crm' => ['leads', 'opportunities', 'follow-ups', 'complaints'],
    'shareholders' => ['register', 'capital', 'distributions', 'statements', 'ownership', 'resolutions'],
    'hr' => ['employees', 'attendance', 'leave', 'payroll', 'advances'],
    'assets' => ['register', 'maintenance', 'depreciation'],
    'documents' => ['library', 'approvals'],
    'reports' => ['production', 'inventory', 'sales', 'finance', 'quality'],
];
