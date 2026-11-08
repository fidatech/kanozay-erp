<?php

namespace App\Support;

/**
 * Explicit locale dictionary with English fallback. Translation keys are
 * presentation labels only; stored business values and CSV money columns
 * remain the exact original source values.
 */
class ReportingLabels
{
    public static function text(string $key):string
    {
        $english=[
            'operations_overview'=>'Operations reporting overview',
            'overview_description'=>'Source-backed counts and financial document amounts. Values retain their original currencies.',
            'export_csv'=>'Export CSV','from'=>'From','to'=>'To',
            'apply_filters'=>'Apply filters','reset'=>'Reset',
            'date_scope_warning'=>'Selected dates apply to source document dates. Open nonconformances and held incoming lots are current outstanding snapshots.',
            'recorded_operations'=>'Recorded operations','purchase_orders'=>'Purchase orders',
            'production_plans'=>'Production plans','released_batches'=>'Released batches',
            'accepted_packages'=>'QC-accepted released packages',
            'delivery_confirmations'=>'Customer delivery confirmations',
            'posted_journals'=>'Posted GL journals',
            'open_nonconformances'=>'Open NCR investigations',
            'held_incoming_lots'=>'Incoming lots on manual QC hold',
            'snapshot_scope_warning'=>'Operational counts are not financial values. Current outstanding cases are not restricted to the selected date range.',
            'sales_by_currency'=>'Sales invoice and credit-note activity by currency',
            'sales_scope_warning'=>'Posted invoice face value and customer credit-note face value are shown separately for document dates in this period. They are not cash receipts, net revenue or profit. No cross-currency total is calculated.',
            'currency'=>'Currency','posted_invoice_count'=>'Posted invoices',
            'invoice_face_value'=>'Posted invoice face value','credit_note_count'=>'Credit notes',
            'credit_note_value'=>'Credit-note face value',
            'no_records'=>'No matching records',
            'laboratory_activity'=>'Laboratory sample activity',
            'sample_source'=>'Sample source','collected'=>'Collected',
            'approved'=>'Approved','rejected'=>'Rejected','pending'=>'Pending review',
            'incoming_material'=>'Incoming materials','finished_batches'=>'Finished batches',
            'sample_scope_warning'=>'Sample counts use collection dates; the statuses shown are current review outcomes and may have changed since collection.',
            'report_registers'=>'Operational report registers',
            'search_records'=>'Search records','search_hint'=>'Reference, code or description',
            'filter'=>'Filter','report_register'=>'Report register','matching_records'=>'matching records',
            'no_filter_matches'=>'No records match these filters.',
            'reporting_note'=>'Report scope',
            'period_close'=>'Monthly financial closing',
            'good_morning'=>'Good morning','good_afternoon'=>'Good afternoon','good_evening'=>'Good evening',
            'dashboard_operations'=>'Recorded Kanozay operations',
            'recent_activity'=>'Recent recorded activity',
            'recent_activity_note'=>'Operational documents and posted journals in the selected period.',
            'no_permission_cards'=>'No dashboard data for your permissions',
            'no_recent_activity'=>'No recent activity found',
            'accessible_records'=>'Accessible source records will appear here when entered.',
            'view_record'=>'View',
            'party_description'=>'Party / Description',
            'status_currency'=>'Status / Currency',
            'production_waste_report'=>'Production waste and rework financial review',
            'production_waste_description'=>'Posted material issues, loss classifications and unresolved financial exceptions.',
            'production_waste_accounting_warning'=>'No duplicate material issue or unsupported waste-expense journal is created.',
            'production_waste_treatment'=>'Financial treatment',
            'production_waste_all'=>'All classifications',
            'production_waste_pending'=>'Independent review pending',
            'production_waste_normal'=>'Reviewed normal process loss',
            'production_waste_abnormal'=>'Abnormal loss — expense treatment required',
            'production_waste_rework'=>'Rework required',
            'production_order'=>'Production order','production_batch'=>'Production batch',
            'material'=>'Material','waste_quantity'=>'Waste quantity',
            'reason'=>'Reason','recorded_at'=>'Recorded at',
            'approval_reference'=>'Approval reference',
        ];
        return (string)(config('erp_reporting_labels.'.app()->getLocale().'.'.$key)
            ??$english[$key]??$key);
    }

    public static function dashboardCard(string $label):string
    {
        return (string)(config('erp_reporting_labels.'.app()->getLocale().'.dashboard.'.$label)
            ??$label);
    }

    public static function column(string $column):string
    {
        return (string)(config('erp_reporting_labels.'.app()->getLocale().'.columns.'.$column)
            ??$column);
    }

    public static function decimal(mixed $value):string
    {
        $original=trim((string)($value??'0'));
        if(!preg_match('/^(-?)(\d+)(\.\d+)?$/D',$original,$match)){
            return $original;
        }
        return $match[1].preg_replace('/\B(?=(\d{3})+(?!\d))/',',',$match[2])
            .($match[3]??'');
    }
}
