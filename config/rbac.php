<?php
return [
  'admin'      => ['*'],
  'sales_rep'  => ['orders:*','s_stock:*','customers:mine','invoices:view'],
  'accountant' => ['invoices:*','payments:*','reports:*'],
  'customer'   => ['orders:create','orders:view:own','invoices:view:own'],
];
