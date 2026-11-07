<?php

/**
 * Explicitly scoped non-posting business workflows. Financial events remain
 * in their dedicated, balanced and approved accounting services.
 * field types: text, textarea, date, time, datetime, decimal, integer, option, lookup
 */
return [
 'purchases.quotations'=>[
  'label'=>'Supplier Quotations','purpose'=>'Record supplier offers against requested materials. This is not a purchase commitment.',
  'fields'=>[
   'supplier'=>['label'=>'Supplier','type'=>'lookup','source'=>'suppliers','required'=>true],
   'material'=>['label'=>'Material','type'=>'lookup','source'=>'materials','required'=>true],
   'quantity'=>['label'=>'Offered quantity','type'=>'decimal','required'=>true],
   'currency'=>['label'=>'Currency','type'=>'lookup','source'=>'currencies','required'=>true],
   'unit_price'=>['label'=>'Quoted unit price','type'=>'decimal','required'=>true],
   'valid_until'=>['label'=>'Offer valid until','type'=>'date','required'=>true],
   'reference'=>['label'=>'Supplier quotation reference','type'=>'text','required'=>true],
   'terms'=>['label'=>'Delivery and payment terms','type'=>'textarea'],
  ]],
 'purchases.shipments'=>[
  'label'=>'Import Shipments & Customs','purpose'=>'Track documented shipment stages. Customs charges require a separate approved landed-cost transaction.',
  'fields'=>[
   'purchase_order'=>['label'=>'Purchase order','type'=>'lookup','source'=>'purchase_orders','required'=>true],
   'shipment_no'=>['label'=>'Shipment / bill of lading reference','type'=>'text','required'=>true],
   'forwarder'=>['label'=>'Forwarder','type'=>'text','required'=>true],
   'origin'=>['label'=>'Origin','type'=>'text','required'=>true],
   'destination'=>['label'=>'Destination','type'=>'text','required'=>true],
   'departed_on'=>['label'=>'Departure date','type'=>'date'],
   'eta'=>['label'=>'Estimated arrival','type'=>'date'],
   'customs_ref'=>['label'=>'Customs entry reference','type'=>'text'],
   'stage'=>['label'=>'Shipment stage','type'=>'option','required'=>true,'options'=>['booked'=>'Booked','in_transit'=>'In transit','customs'=>'In customs','cleared'=>'Cleared','delivered'=>'Delivered']],
   'remarks'=>['label'=>'Tracking remarks','type'=>'textarea'],
  ]],
 'purchases.supplier-performance'=>[
  'label'=>'Supplier Performance','purpose'=>'Maintain dated supplier evaluations using documented evidence.',
  'fields'=>[
   'supplier'=>['label'=>'Supplier','type'=>'lookup','source'=>'suppliers','required'=>true],
   'period_start'=>['label'=>'Evaluation period starts','type'=>'date','required'=>true],
   'period_end'=>['label'=>'Evaluation period ends','type'=>'date','required'=>true],
   'quality_score'=>['label'=>'Quality score (0–100)','type'=>'integer','min'=>0,'max'=>100,'required'=>true],
   'delivery_score'=>['label'=>'On-time delivery score (0–100)','type'=>'integer','min'=>0,'max'=>100,'required'=>true],
   'evidence'=>['label'=>'Evidence / document reference','type'=>'text','required'=>true],
   'notes'=>['label'=>'Assessment notes','type'=>'textarea'],
  ]],
 'inventory.locations'=>[
  'label'=>'Storage Locations','purpose'=>'Warehouse bin and aisle directory. Stock moves still require an inventory transfer.',
  'fields'=>[
   'warehouse'=>['label'=>'Warehouse','type'=>'lookup','source'=>'warehouses','required'=>true],
   'code'=>['label'=>'Bin or rack code','type'=>'text','required'=>true],
   'zone'=>['label'=>'Zone / aisle','type'=>'text'],
   'capacity'=>['label'=>'Nominal capacity','type'=>'decimal'],
   'capacity_unit'=>['label'=>'Capacity unit','type'=>'text'],
   'notes'=>['label'=>'Storage constraints','type'=>'textarea'],
  ]],
 'inventory.units'=>[
  'label'=>'Units of Measure','purpose'=>'Controlled unit reference catalog. Existing item-unit values are preserved until explicitly migrated.',
  'fields'=>[
   'name'=>['label'=>'Unit name','type'=>'text','required'=>true],
   'symbol'=>['label'=>'Symbol','type'=>'text','required'=>true],
   'precision'=>['label'=>'Decimal places (0–6)','type'=>'integer','required'=>true,'min'=>0,'max'=>6],
   'category'=>['label'=>'Measurement category','type'=>'option','required'=>true,'options'=>['mass'=>'Mass','volume'=>'Volume','count'=>'Count','length'=>'Length','other'=>'Other']],
  ]],
 'inventory.categories'=>[
  'label'=>'Product Categories','purpose'=>'Reference product classification register. Category assignment to legacy products requires a verified mapping.',
  'fields'=>[
   'code'=>['label'=>'Category code','type'=>'text','required'=>true],
   'name'=>['label'=>'Category name','type'=>'text','required'=>true],
   'group'=>['label'=>'Type','type'=>'option','required'=>true,'options'=>['raw'=>'Raw material','packaging'=>'Packaging','finished'=>'Finished product','other'=>'Other']],
   'description'=>['label'=>'Description','type'=>'textarea'],
  ]],
 'manufacturing.processes'=>[
  'label'=>'Production Processes','purpose'=>'Versioned process instruction approval independent of unvalidated ingredient quantities.',
  'fields'=>[
   'recipe'=>['label'=>'Recipe','type'=>'lookup','source'=>'recipes','required'=>true],
   'stage'=>['label'=>'Process stage','type'=>'text','required'=>true],
   'sequence'=>['label'=>'Stage sequence','type'=>'integer','required'=>true,'min'=>1,'max'=>9999],
   'duration_minutes'=>['label'=>'Target minutes','type'=>'integer','min'=>1,'max'=>525600],
   'procedure'=>['label'=>'Documented process instructions','type'=>'textarea','required'=>true],
   'safety'=>['label'=>'Safety requirements','type'=>'textarea'],
  ]],
 'manufacturing.routing'=>[
  'label'=>'Production Routing','purpose'=>'Assign operation sequence and verified equipment to process stages. Does not consume materials.',
  'fields'=>[
   'recipe'=>['label'=>'Recipe','type'=>'lookup','source'=>'recipes','required'=>true],
   'step'=>['label'=>'Operation number','type'=>'integer','required'=>true,'min'=>1,'max'=>9999],
   'operation'=>['label'=>'Operation name','type'=>'text','required'=>true],
   'equipment'=>['label'=>'Production equipment','type'=>'lookup','source'=>'equipment'],
   'estimated_minutes'=>['label'=>'Estimated duration in minutes','type'=>'integer','min'=>1,'max'=>525600],
   'instructions'=>['label'=>'Operation instructions','type'=>'textarea','required'=>true],
  ]],
 'manufacturing.packaging'=>[
  'label'=>'Packaging Specifications','purpose'=>'Record approved finished-product pack presentation; production quantities still derive from approved recipes.',
  'fields'=>[
   'finished_good'=>['label'=>'Finished good','type'=>'lookup','source'=>'finished_goods','required'=>true],
   'packaging_material'=>['label'=>'Packaging inventory material','type'=>'lookup','source'=>'materials','required'=>true],
   'units_per_pack'=>['label'=>'Units per pack','type'=>'integer','required'=>true,'min'=>1,'max'=>1000000],
   'tare_weight_kg'=>['label'=>'Empty pack weight (kg)','type'=>'decimal'],
   'label_reference'=>['label'=>'Approved label artwork reference','type'=>'text','required'=>true],
   'instructions'=>['label'=>'Packaging instruction','type'=>'textarea'],
  ]],
 'laboratory.calibration'=>[
  'label'=>'Equipment Calibration','purpose'=>'Track traceable calibration and overdue certificates; not proof that the calibration was accredited.',
  'fields'=>[
   'equipment'=>['label'=>'Equipment','type'=>'lookup','source'=>'equipment','required'=>true],
   'calibrated_on'=>['label'=>'Calibrated on','type'=>'date','required'=>true],
   'due_on'=>['label'=>'Next calibration due','type'=>'date','required'=>true],
   'result'=>['label'=>'Calibration result','type'=>'option','required'=>true,'options'=>['pass'=>'Pass','fail'=>'Fail','conditional'=>'Conditional']],
   'certificate'=>['label'=>'Calibration certificate reference','type'=>'text','required'=>true],
   'provider'=>['label'=>'Calibration provider','type'=>'text'],
   'notes'=>['label'=>'Observed deviations','type'=>'textarea'],
  ]],
 'sales.dispatch'=>[
  'label'=>'Dispatch Planning','purpose'=>'Plan loading and carrier assignment. Stock has already been dispatched by sales invoice posting.',
  'fields'=>[
   'invoice'=>['label'=>'Sales invoice','type'=>'lookup','source'=>'sales_invoices','required'=>true],
   'planned_on'=>['label'=>'Loading date','type'=>'date','required'=>true],
   'warehouse'=>['label'=>'Loading warehouse','type'=>'lookup','source'=>'warehouses'],
   'carrier'=>['label'=>'Carrier / driver','type'=>'text','required'=>true],
   'vehicle'=>['label'=>'Vehicle reference','type'=>'text'],
   'delivery_address'=>['label'=>'Delivery destination','type'=>'textarea','required'=>true],
   'instructions'=>['label'=>'Special instructions','type'=>'textarea'],
  ]],
 'sales.delivery-tracking'=>[
  'label'=>'Delivery Tracking','purpose'=>'Timestamped status events for issued delivery notes. A status update does not post inventory.',
  'fields'=>[
   'delivery_note'=>['label'=>'Delivery note','type'=>'lookup','source'=>'delivery_notes','required'=>true],
   'event_on'=>['label'=>'Tracking date','type'=>'date','required'=>true],
   'status'=>['label'=>'Status','type'=>'option','required'=>true,'options'=>['loaded'=>'Loaded','in_transit'=>'In transit','attempted'=>'Delivery attempted','delivered'=>'Delivered','returned'=>'Returned to depot']],
   'location'=>['label'=>'Checkpoint / locality','type'=>'text','required'=>true],
   'proof_reference'=>['label'=>'Proof of delivery reference','type'=>'text'],
   'notes'=>['label'=>'Delivery notes','type'=>'textarea'],
  ]],
 'sales.sales-targets'=>[
  'label'=>'Sales Targets','purpose'=>'Assign monthly revenue targets for planning. Target achievement uses posted invoice reports.',
  'fields'=>[
   'employee'=>['label'=>'Responsible employee','type'=>'lookup','source'=>'employees','required'=>true],
   'target_month'=>['label'=>'Target month (first day)','type'=>'date','required'=>true],
   'currency'=>['label'=>'Currency','type'=>'lookup','source'=>'currencies','required'=>true],
   'amount'=>['label'=>'Target revenue','type'=>'decimal','required'=>true],
   'region'=>['label'=>'Region / territory','type'=>'text'],
   'notes'=>['label'=>'Target assumptions','type'=>'textarea'],
  ]],
 'hr.contracts'=>[
  'label'=>'Employee Contracts','purpose'=>'Contract terms history; signed agreements belong in the private document library.',
  'fields'=>[
   'employee'=>['label'=>'Employee','type'=>'lookup','source'=>'employees','required'=>true],
   'contract_no'=>['label'=>'Contract reference','type'=>'text','required'=>true],
   'contract_type'=>['label'=>'Contract type','type'=>'option','required'=>true,'options'=>['permanent'=>'Permanent','fixed_term'=>'Fixed term','temporary'=>'Temporary','consultant'=>'Consultant']],
   'started_on'=>['label'=>'Effective date','type'=>'date','required'=>true],
   'ends_on'=>['label'=>'End date','type'=>'date'],
   'agreement_reference'=>['label'=>'Signed document reference','type'=>'text','required'=>true],
   'terms'=>['label'=>'Contract notes','type'=>'textarea'],
  ]],
 'hr.shifts'=>[
  'label'=>'Shift Scheduling','purpose'=>'Schedule planned staff shifts. Actual attendance is recorded separately.',
  'fields'=>[
   'employee'=>['label'=>'Employee','type'=>'lookup','source'=>'employees','required'=>true],
   'branch'=>['label'=>'Branch','type'=>'lookup','source'=>'branches'],
   'shift_date'=>['label'=>'Shift date','type'=>'date','required'=>true],
   'starts_at'=>['label'=>'Start time','type'=>'time','required'=>true],
   'ends_at'=>['label'=>'End time','type'=>'time','required'=>true],
   'duties'=>['label'=>'Assigned duties','type'=>'textarea'],
  ]],
 'hr.overtime'=>[
  'label'=>'Overtime Requests','purpose'=>'Approve documented overtime hours; payroll amounts are entered only through reviewed payroll components.',
  'fields'=>[
   'employee'=>['label'=>'Employee','type'=>'lookup','source'=>'employees','required'=>true],
   'worked_on'=>['label'=>'Overtime date','type'=>'date','required'=>true],
   'hours'=>['label'=>'Hours','type'=>'decimal','required'=>true],
   'reason'=>['label'=>'Operational reason','type'=>'textarea','required'=>true],
   'supervisor_ref'=>['label'=>'Supervisor reference','type'=>'text'],
  ]],
 'hr.exits'=>[
  'label'=>'Employee Separation','purpose'=>'Manage offboarding approvals and evidence. Employee access and financial settlement remain separately controlled.',
  'fields'=>[
   'employee'=>['label'=>'Employee','type'=>'lookup','source'=>'employees','required'=>true],
   'last_working_on'=>['label'=>'Last working date','type'=>'date','required'=>true],
   'reason'=>['label'=>'Separation reason','type'=>'option','required'=>true,'options'=>['resignation'=>'Resignation','contract_end'=>'Contract ended','termination'=>'Termination','retirement'=>'Retirement','other'=>'Other']],
   'asset_clearance'=>['label'=>'Asset clearance reference','type'=>'text'],
   'finance_clearance'=>['label'=>'Final settlement reference','type'=>'text'],
   'notes'=>['label'=>'Exit notes','type'=>'textarea'],
  ]],
];
