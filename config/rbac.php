<?php
return [
  'admin'      => ['*'],
  'sales_rep'  => ['orders:*','s_stock:*','customers:mine','invoices:view'],
  'accountant' => ['accounting:*','invoices:*','payments:*','reports:*','commissions:*','customers:view','customers:balance','inventory:view'],
  'customer'   => ['orders:create','orders:view:own','invoices:view:own'],
];
