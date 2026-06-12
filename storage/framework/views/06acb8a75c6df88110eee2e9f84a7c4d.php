<?php $__env->startSection('code', '403'); ?>
<?php $__env->startSection('title', 'Access Forbidden'); ?>
<?php $__env->startSection('message', 'You don\'t have permission to access this resource. If you believe this is an error, please contact your administrator.'); ?>

<?php $__env->startSection('icon'); ?>
<svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
</svg>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('errors.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/haseeb/Downloads/FileSharing/fileshare-next/hrm-management-system/resources/views/errors/403.blade.php ENDPATH**/ ?>