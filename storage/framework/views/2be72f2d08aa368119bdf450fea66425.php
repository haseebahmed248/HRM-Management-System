<?php $__env->startSection('code', '404'); ?>
<?php $__env->startSection('title', 'Page Not Found'); ?>
<?php $__env->startSection('message', 'The page you\'re looking for doesn\'t exist or has been moved. Please check the URL or navigate back to the homepage.'); ?>

<?php $__env->startSection('icon'); ?>
<svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z"/>
</svg>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('errors.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /Users/haseeb/Downloads/FileSharing/fileshare-next/hrm-management-system/resources/views/errors/404.blade.php ENDPATH**/ ?>