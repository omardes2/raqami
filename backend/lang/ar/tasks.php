<?php

return [
    // Generic
    'stale' => 'تم تحديث هذا العنصر بواسطة شخص آخر. أعد التحميل وحاول مرة أخرى.',
    'employee_invalid' => 'الموظف المحدد غير صالح لهذه الشركة.',
    'scope_target_invalid' => 'النطاق التنظيمي المحدد غير صالح.',

    // Projects
    'project_forbidden' => 'لا يُسمح لك بإدارة هذا المشروع.',
    'project_scope_forbidden' => 'لا يُسمح لك بإنشاء مشروع في هذا النطاق.',
    'project_governance_forbidden' => 'يمكن فقط لمالك المشروع أو مدير مخوّل تغيير إعدادات حوكمة المشروع.',
    'project_already_archived' => 'هذا المشروع مؤرشف بالفعل.',
    'project_not_archived' => 'هذا المشروع غير مؤرشف.',
    'project_archived_readonly' => 'هذا المشروع مؤرشف ولا يمكن تعديله.',
    'member_is_owner' => 'مالك المشروع مشارك بالفعل ولا يمكن إضافته كعضو.',

    // Tasks
    'task_forbidden' => 'لا يُسمح لك بالتصرف في هذه المهمة.',
    'task_create_forbidden' => 'لا يُسمح لك بإنشاء مهمة في هذا النطاق.',
    'task_archived_readonly' => 'هذه المهمة مؤرشفة ولا يمكن تعديلها.',
    'task_scope_required' => 'تتطلب المهمة المستقلة نطاقًا تنظيميًا.',
    'task_scope_conflict' => 'لا يمكن لمهمة داخل مشروع أن تحدد نطاقها الخاص.',
    'project_closed_for_tasks' => 'هذا المشروع مؤرشف أو مكتمل ولا يمكنه استقبال مهام جديدة.',
    'idempotency_conflict' => 'طلب مختلف استخدم مفتاح التكرار هذا بالفعل.',

    // Status
    'status_invalid' => 'الحالة المحددة غير صالحة.',
    'status_inactive' => 'لا يمكن إسناد حالة غير مفعّلة إلى مهمة.',
    'status_category_locked' => 'لا يمكن تغيير فئة حالة مستخدمة بالفعل.',
    'status_default_required' => 'يجب أن تحتفظ الشركة بحالة افتراضية مفعّلة واحدة بالضبط.',
    'status_in_use' => 'لا يمكن حذف حالة مرتبطة بمهام؛ قم بتعطيلها بدلاً من ذلك.',

    // Subtasks
    'subtask_depth' => 'المهام الفرعية محدودة بمستوى واحد.',
    'subtask_parent_archived' => 'لا يمكن إضافة مهمة فرعية إلى مهمة مؤرشفة.',
    'subtask_scope_mismatch' => 'يجب أن تشارك المهمة الفرعية مشروع أو نطاق المهمة الأم.',
    'self_parent' => 'لا يمكن أن تكون المهمة أمًّا لنفسها.',

    // Assignment
    'assignee_inactive' => 'لا يمكن إسناد مهمة جديدة لموظف غير نشط.',
    'assignee_scope_forbidden' => 'هذا الموظف ليس مسندًا صالحًا لنطاق المهمة.',
    'assign_forbidden' => 'لا يُسمح لك بإسناد هذه المهمة.',
    'assignee_not_project_participant' => 'أضف الموظف كعضو في المشروع قبل إسناده.',

    // Comments
    'comment_forbidden' => 'لا يُسمح لك بالتعليق على هذه المهمة.',
    'comment_edit_forbidden' => 'لا يُسمح لك بتعديل هذا التعليق.',
    'comment_deleted' => 'تم حذف هذا التعليق.',

    // Mentions
    'mention_invalid' => 'لا يمكن الإشارة إلى واحد أو أكثر من المستخدمين المذكورين في هذه المهمة.',

    // Attachments
    'attachment_comment_mismatch' => 'المرفق لا ينتمي إلى هذه المهمة أو هذا التعليق.',

    // Kanban
    'board_rank_project_only' => 'يطبّق الترتيب اليدوي فقط على مهام المشروع الرئيسية.',

    // Settings
    'settings_forbidden' => 'لا يُسمح لك بإدارة إعدادات المهام.',

    // UI notices
    'subtasks_incomplete_notice' => 'تحتوي هذه المهمة على مهام فرعية غير مكتملة.',
];
