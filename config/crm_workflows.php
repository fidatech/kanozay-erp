<?php

/*
 * All definitions describe real typed database registers. They never
 * authorize financial/stock posting or manufacture accounting balances.
 */
return [
    'segments' => [
        'table' => 'crm_segments', 'heading' => 'Customer Segments',
        'primary' => 'name', 'search' => ['name'],
        'columns' => ['name', 'description', 'is_active'],
        'fields' => [
            'name' => ['label'=>'Segment name', 'required'=>true],
            'description' => ['label'=>'Description', 'type'=>'textarea'],
            'is_active' => ['label'=>'Active', 'type'=>'select', 'options'=>['1'=>'Yes','0'=>'No'], 'default'=>'1'],
        ],
    ],
    'leads' => [
        'table'=>'crm_leads', 'heading'=>'Leads', 'primary'=>'name',
        'prefix'=>'LEAD', 'search'=>['number','name','company','email','phone'],
        'columns'=>['number','name','company','phone','status','assigned_to'],
        'fields'=>[
            'name'=>['label'=>'Contact name','required'=>true],
            'company'=>['label'=>'Organization'],
            'phone'=>['label'=>'Phone'],
            'email'=>['label'=>'Email','type'=>'email'],
            'source'=>['label'=>'Lead source'],
            'segment_id'=>['label'=>'Segment','type'=>'relation','source'=>'segments'],
            'status'=>['label'=>'Status','type'=>'select','options'=>['new'=>'New','contacted'=>'Contacted','qualified'=>'Qualified','unqualified'=>'Unqualified','converted'=>'Converted'],'default'=>'new'],
            'assigned_to'=>['label'=>'Assigned employee','type'=>'relation','source'=>'users'],
            'notes'=>['label'=>'Notes','type'=>'textarea'],
        ],
    ],
    'opportunities'=>[
        'table'=>'crm_opportunities','heading'=>'Opportunities','primary'=>'title',
        'prefix'=>'OPP','search'=>['number','title'],
        'columns'=>['number','title','lead_id','trade_party_id','amount','currency_id','stage','expected_close_on'],
        'fields'=>[
            'title'=>['label'=>'Opportunity title','required'=>true],
            'lead_id'=>['label'=>'Lead','type'=>'relation','source'=>'leads'],
            'trade_party_id'=>['label'=>'Customer','type'=>'relation','source'=>'customers'],
            'currency_id'=>['label'=>'Currency','type'=>'relation','source'=>'currencies'],
            'amount'=>['label'=>'Expected amount','type'=>'money'],
            'stage'=>['label'=>'Pipeline stage','type'=>'select','options'=>['qualification'=>'Qualification','proposal'=>'Proposal','negotiation'=>'Negotiation','won'=>'Won','lost'=>'Lost'],'default'=>'qualification'],
            'probability'=>['label'=>'Probability (%)','type'=>'percentage','default'=>'0'],
            'expected_close_on'=>['label'=>'Expected close','type'=>'date'],
            'assigned_to'=>['label'=>'Assigned employee','type'=>'relation','source'=>'users'],
            'notes'=>['label'=>'Notes','type'=>'textarea'],
        ],
    ],
    'contacts'=>[
        'table'=>'crm_contacts','heading'=>'Customer Contacts','primary'=>'name',
        'search'=>['name','phone','email'],
        'columns'=>['name','position','phone','email','trade_party_id'],
        'fields'=>[
            'name'=>['label'=>'Full name','required'=>true],
            'position'=>['label'=>'Position'],
            'phone'=>['label'=>'Phone'],
            'email'=>['label'=>'Email','type'=>'email'],
            'trade_party_id'=>['label'=>'Customer','type'=>'relation','source'=>'customers'],
            'notes'=>['label'=>'Notes','type'=>'textarea'],
        ],
    ],
    'activities'=>[
        'table'=>'crm_activities','heading'=>'Customer Activities','primary'=>'subject',
        'search'=>['subject'],
        'columns'=>['subject','type','lead_id','opportunity_id','trade_party_id','occurred_at'],
        'fields'=>[
            'subject'=>['label'=>'Activity subject','required'=>true],
            'type'=>['label'=>'Activity type','type'=>'select','required'=>true,'options'=>['call'=>'Phone call','email'=>'Email','meeting'=>'Meeting','visit'=>'Visit','note'=>'Internal note']],
            'lead_id'=>['label'=>'Related lead','type'=>'relation','source'=>'leads'],
            'opportunity_id'=>['label'=>'Related opportunity','type'=>'relation','source'=>'opportunities'],
            'trade_party_id'=>['label'=>'Related customer','type'=>'relation','source'=>'customers'],
            'occurred_at'=>['label'=>'Activity date and time','type'=>'datetime','required'=>true],
            'notes'=>['label'=>'Description','type'=>'textarea'],
        ],
    ],
    'follow-ups'=>[
        'table'=>'crm_follow_ups','heading'=>'Follow-Ups','primary'=>'subject',
        'search'=>['subject'],
        'columns'=>['subject','due_at','status','assigned_to','lead_id','trade_party_id'],
        'fields'=>[
            'subject'=>['label'=>'Follow-up subject','required'=>true],
            'lead_id'=>['label'=>'Related lead','type'=>'relation','source'=>'leads'],
            'opportunity_id'=>['label'=>'Related opportunity','type'=>'relation','source'=>'opportunities'],
            'trade_party_id'=>['label'=>'Related customer','type'=>'relation','source'=>'customers'],
            'due_at'=>['label'=>'Due date and time','type'=>'datetime','required'=>true],
            'status'=>['label'=>'Status','type'=>'select','options'=>['pending'=>'Pending','completed'=>'Completed','cancelled'=>'Cancelled'],'default'=>'pending'],
            'assigned_to'=>['label'=>'Assigned employee','type'=>'relation','source'=>'users'],
            'notes'=>['label'=>'Details','type'=>'textarea'],
        ],
    ],
    'complaints'=>[
        'table'=>'crm_complaints','heading'=>'Customer Complaints','primary'=>'subject',
        'prefix'=>'COMP','search'=>['number','subject'],
        'columns'=>['number','subject','trade_party_id','priority','status','assigned_to'],
        'fields'=>[
            'subject'=>['label'=>'Complaint subject','required'=>true],
            'trade_party_id'=>['label'=>'Customer','type'=>'relation','source'=>'customers','required'=>true],
            'priority'=>['label'=>'Priority','type'=>'select','options'=>['low'=>'Low','medium'=>'Medium','high'=>'High','urgent'=>'Urgent'],'default'=>'medium'],
            'status'=>['label'=>'Status','type'=>'select','options'=>['open'=>'Open','in_progress'=>'In progress','resolved'=>'Resolved','closed'=>'Closed'],'default'=>'open'],
            'description'=>['label'=>'Complaint description','type'=>'textarea','required'=>true],
            'resolution'=>['label'=>'Resolution notes','type'=>'textarea'],
            'assigned_to'=>['label'=>'Assigned employee','type'=>'relation','source'=>'users'],
        ],
    ],
    'visit-plans'=>[
        'table'=>'crm_visit_plans','heading'=>'Customer Visit Plans','primary'=>'purpose',
        'search'=>['purpose'],
        'columns'=>['purpose','scheduled_at','status','assigned_to','lead_id','trade_party_id'],
        'fields'=>[
            'purpose'=>['label'=>'Visit purpose','required'=>true],
            'lead_id'=>['label'=>'Related lead','type'=>'relation','source'=>'leads'],
            'trade_party_id'=>['label'=>'Related customer','type'=>'relation','source'=>'customers'],
            'scheduled_at'=>['label'=>'Scheduled date and time','type'=>'datetime','required'=>true],
            'status'=>['label'=>'Status','type'=>'select','options'=>['scheduled'=>'Scheduled','completed'=>'Completed','cancelled'=>'Cancelled'],'default'=>'scheduled'],
            'assigned_to'=>['label'=>'Assigned employee','type'=>'relation','source'=>'users'],
            'notes'=>['label'=>'Preparation notes','type'=>'textarea'],
            'outcome'=>['label'=>'Visit outcome','type'=>'textarea'],
        ],
    ],
];
