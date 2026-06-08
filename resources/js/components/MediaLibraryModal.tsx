import { hasPermission } from '@/utils/authorization';
import { usePage } from '@inertiajs/react';
import {
    Check,
    FileArchive,
    File as FileIcon,
    FileSpreadsheet,
    FileText,
    Image as ImageIcon,
    ImageOff,
    Music,
    Plus,
    Search,
    Upload,
    Video,
} from 'lucide-react';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Badge } from './ui/badge';
import { Button } from './ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from './ui/dialog';
import { Input } from './ui/input';

interface MediaItem {
    id: number;
    name: string;
    file_name: string;
    url: string;
    thumb_url: string;
    size: number;
    mime_type: string;
    created_at: string;
}

interface MediaLibraryModalProps {
    isOpen: boolean;
    onClose: () => void;
    onSelect: (url: string) => void;
    multiple?: boolean;
}

export default function MediaLibraryModal({ isOpen, onClose, onSelect, multiple = false }: MediaLibraryModalProps) {
    const { auth } = usePage().props as any;
    const permissions = auth?.permissions || [];
    const canCreateMedia = hasPermission(permissions, 'create-media');
    const canManageMedia = hasPermission(permissions, 'manage-media');

    const [media, setMedia] = useState<MediaItem[]>([]);
    const [directories, setDirectories] = useState<any[]>([]);
    const [currentDirectory, setCurrentDirectory] = useState<number | null>(null);
    const [filteredMedia, setFilteredMedia] = useState<MediaItem[]>([]);
    const [loading, setLoading] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [selectedItems, setSelectedItems] = useState<string[]>([]);
    const [dragActive, setDragActive] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');
    const [failedPreviews, setFailedPreviews] = useState<Record<number, boolean>>({});
    const [currentPage, setCurrentPage] = useState(1);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const itemsPerPage = 24;

    const fetchMedia = useCallback(async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams();
            if (currentDirectory) {
                params.append('directory_id', currentDirectory.toString());
            }

            const response = await fetch(`${route('api.media.index')}?${params}`, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();
            const mediaArray = Array.isArray(data.media) ? data.media : Array.isArray(data) ? data : [];
            setMedia(mediaArray);
            setDirectories(data.directories || []);
            setFilteredMedia(mediaArray);
            setFailedPreviews({});
        } catch (error) {
            toast.error('Failed to load media');
        } finally {
            setLoading(false);
        }
    }, [currentDirectory]);

    useEffect(() => {
        if (isOpen) {
            fetchMedia();
            setSearchTerm('');
        }
    }, [isOpen, fetchMedia]);

    // Filter media based on search term
    useEffect(() => {
        if (!searchTerm.trim()) {
            setFilteredMedia(media);
        } else {
            const filtered = media.filter(
                (item) =>
                    item.name.toLowerCase().includes(searchTerm.toLowerCase()) || item.file_name.toLowerCase().includes(searchTerm.toLowerCase()),
            );
            setFilteredMedia(filtered);
        }
        setCurrentPage(1);
    }, [searchTerm, media]);

    // Pagination calculations
    const totalPages = Math.ceil(filteredMedia.length / itemsPerPage);
    const startIndex = (currentPage - 1) * itemsPerPage;
    const currentMedia = filteredMedia.slice(startIndex, startIndex + itemsPerPage);

    const handleFileUpload = async (files: FileList) => {
        setUploading(true);

        const validFiles = Array.from(files);

        if (validFiles.length === 0) {
            setUploading(false);
            return;
        }

        const formData = new FormData();
        validFiles.forEach((file) => {
            formData.append('files[]', file);
        });

        try {
            const response = await fetch(route('api.media.batch'), {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            const result = await response.json();

            if (response.ok) {
                if (result.data && result.data.length > 0) {
                    setMedia((prev) => [...result.data, ...prev]);
                }

                // Show appropriate success/warning messages
                if (result.errors && result.errors.length > 0) {
                    toast.warning(result.message || `${result.data?.length || 0} uploaded, ${result.errors.length} failed`);
                    result.errors.forEach((error: string) => {
                        toast.error(error, { duration: 5000 });
                    });
                } else {
                    toast.success(result.message || `${result.data?.length || 0} file(s) uploaded successfully`);
                }

                if (multiple && result.data?.length > 0) {
                    setSelectedItems((prev) => [...prev, ...result.data.map((item: MediaItem) => item.url)]);
                }
            } else {
                toast.error(result.message || 'Failed to upload files');
                if (result.errors) {
                    result.errors.forEach((error: string) => {
                        toast.error(error, { duration: 5000 });
                    });
                }
            }
        } catch (error) {
            toast.error('Error uploading files');
        }

        setUploading(false);
    };

    const handleUploadInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        if (e.target.files && e.target.files.length > 0) {
            handleFileUpload(e.target.files);
        }

        e.target.value = '';
    };

    const handleDrag = (e: React.DragEvent) => {
        e.preventDefault();
        e.stopPropagation();
        if (e.type === 'dragenter' || e.type === 'dragover') {
            setDragActive(true);
        } else if (e.type === 'dragleave') {
            setDragActive(false);
        }
    };

    const handleDrop = (e: React.DragEvent) => {
        e.preventDefault();
        e.stopPropagation();
        setDragActive(false);

        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            handleFileUpload(e.dataTransfer.files);
        }
    };

    const handleSelect = (url: string) => {
        if (multiple) {
            setSelectedItems((prev) => (prev.includes(url) ? prev.filter((item) => item !== url) : [...prev, url]));
        } else {
            onSelect(url);
            onClose();
        }
    };

    const handleConfirmSelection = () => {
        if (multiple && selectedItems.length > 0) {
            onSelect(selectedItems.join(','));
            onClose();
        }
    };

    const getFileExtension = (item: MediaItem) => {
        return item.file_name.split('.').pop()?.toUpperCase() || item.mime_type.split('/')[1]?.toUpperCase() || 'FILE';
    };

    const formatFileSize = (size: number) => {
        if (!size) return '0 KB';

        if (size < 1024 * 1024) {
            return `${Math.max(1, Math.round(size / 1024))} KB`;
        }

        return `${(size / (1024 * 1024)).toFixed(1)} MB`;
    };

    const getFileIcon = (mimeType: string, previewFailed = false) => {
        if (mimeType.startsWith('image/')) {
            return previewFailed ? <ImageOff className="h-8 w-8" /> : <ImageIcon className="h-8 w-8" />;
        }
        if (mimeType.includes('pdf')) return <FileText className="h-8 w-8 text-red-600" />;
        if (mimeType.includes('word') || mimeType.includes('document')) return <FileText className="h-8 w-8 text-blue-600" />;
        if (mimeType.includes('csv') || mimeType.includes('spreadsheet')) return <FileSpreadsheet className="h-8 w-8 text-green-600" />;
        if (mimeType.includes('zip') || mimeType.includes('archive')) return <FileArchive className="h-8 w-8 text-amber-600" />;
        if (mimeType.startsWith('video/')) return <Video className="h-8 w-8 text-purple-600" />;
        if (mimeType.startsWith('audio/')) return <Music className="h-8 w-8 text-orange-600" />;
        return <FileIcon className="text-muted-foreground h-8 w-8" />;
    };

    return (
        <Dialog open={isOpen} onOpenChange={onClose}>
            <DialogContent className="flex h-[95vh] max-w-7xl flex-col">
                <DialogHeader className="border-b pb-6">
                    <div className="flex items-center gap-3">
                        <div className="bg-primary/10 rounded-lg p-2">
                            <ImageIcon className="text-primary h-5 w-5" />
                        </div>
                        <div>
                            <DialogTitle className="text-xl font-semibold">
                                Media Library
                                {filteredMedia.length > 0 && (
                                    <Badge variant="secondary" className="ml-2 text-xs">
                                        {filteredMedia.length}
                                    </Badge>
                                )}
                            </DialogTitle>
                            <DialogDescription className="text-muted-foreground mt-1 text-sm">
                                Browse and select media files from your library
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <div className="flex flex-1 flex-col space-y-4 overflow-hidden">
                    {/* Directory Navigation */}
                    {directories.length > 0 && (
                        <div className="bg-muted/30 rounded-lg border p-3">
                            <div className="flex items-center gap-2">
                                <div className="scrollbar-thin scrollbar-thumb-muted-foreground/20 scrollbar-track-transparent max-h-24 overflow-y-auto">
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            variant={currentDirectory === null ? 'default' : 'ghost'}
                                            size="sm"
                                            onClick={() => setCurrentDirectory(null)}
                                            className="h-7 px-3 text-xs"
                                        >
                                            All Files
                                        </Button>
                                        {directories.map((dir: any) => (
                                            <Button
                                                key={dir.id}
                                                variant={currentDirectory === dir.id ? 'default' : 'ghost'}
                                                size="sm"
                                                onClick={() => setCurrentDirectory(dir.id)}
                                                className="h-7 px-3 text-xs"
                                            >
                                                {dir.name}
                                            </Button>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Header with Search and Upload */}
                    <div className="flex flex-col gap-4 sm:flex-row">
                        <div className="relative flex-1">
                            <Search className="text-muted-foreground absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 transform" />
                            <Input
                                placeholder="Search media files..."
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                className="h-10 pl-10"
                            />
                        </div>

                        {canCreateMedia && (
                            <div className="flex gap-2">
                                <Input
                                    ref={fileInputRef}
                                    type="file"
                                    multiple
                                    accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx"
                                    onChange={handleUploadInputChange}
                                    className="hidden"
                                />
                                <Button type="button" onClick={() => fileInputRef.current?.click()} disabled={uploading} className="h-10 px-4">
                                    <Plus className="mr-2 h-4 w-4" />
                                    {uploading ? 'Uploading...' : 'Upload'}
                                </Button>
                            </div>
                        )}
                    </div>

                    {/* Stats and Selection Info */}
                    <div className="text-muted-foreground bg-muted/20 flex items-center justify-between rounded-lg border px-4 py-3 text-sm">
                        <div className="flex items-center gap-4">
                            <span className="font-medium">{filteredMedia.length} files</span>
                            {totalPages > 1 && (
                                <span>
                                    Page {currentPage} of {totalPages}
                                </span>
                            )}
                        </div>
                        {multiple && selectedItems.length > 0 && (
                            <Badge variant="default" className="px-2 py-1 text-xs">
                                {selectedItems.length} selected
                            </Badge>
                        )}
                    </div>

                    {/* Media Grid */}
                    <div className="bg-muted/10 flex flex-1 flex-col overflow-hidden rounded-lg border">
                        {loading ? (
                            <div className="flex flex-1 items-center justify-center">
                                <div className="text-center">
                                    <div className="border-primary mx-auto mb-4 h-8 w-8 animate-spin rounded-full border-b-2"></div>
                                    <p className="text-muted-foreground">Loading media...</p>
                                </div>
                            </div>
                        ) : filteredMedia.length === 0 ? (
                            <div className="flex flex-1 items-center justify-center py-16">
                                <div className="max-w-sm text-center">
                                    <div
                                        className={`mx-auto mb-6 flex h-24 w-24 items-center justify-center rounded-xl border-2 border-dashed transition-colors ${
                                            dragActive ? 'border-primary bg-primary/5' : 'border-muted-foreground/25'
                                        }`}
                                        onDragEnter={handleDrag}
                                        onDragLeave={handleDrag}
                                        onDragOver={handleDrag}
                                        onDrop={handleDrop}
                                    >
                                        <Upload className="text-muted-foreground h-10 w-10" />
                                    </div>

                                    <div className="mb-6 space-y-3">
                                        <h3 className="text-lg font-semibold">No media files found</h3>
                                        {searchTerm && (
                                            <p className="text-muted-foreground text-sm">
                                                No results for <span className="text-foreground font-medium">"${searchTerm}"</span>
                                            </p>
                                        )}
                                        <p className="text-muted-foreground text-sm">
                                            {searchTerm ? 'Try a different search term or upload new images' : 'Upload images to get started'}
                                        </p>
                                    </div>

                                    {canCreateMedia && (
                                        <Button type="button" onClick={() => fileInputRef.current?.click()} disabled={uploading}>
                                            <Plus className="mr-2 h-4 w-4" />
                                            Upload Files
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ) : (
                            <div className="flex-1 overflow-y-auto p-6">
                                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6">
                                    {currentMedia.map((item) => {
                                        const previewFailed = Boolean(failedPreviews[item.id]);
                                        const showImagePreview = item.mime_type.startsWith('image/') && !previewFailed;
                                        const isSelected = selectedItems.includes(item.url);

                                        return (
                                            <button
                                                key={item.id}
                                                type="button"
                                                className={`group bg-background focus-visible:ring-primary relative overflow-hidden rounded-lg border text-left transition-all duration-200 focus-visible:ring-2 focus-visible:outline-none ${
                                                    isSelected
                                                        ? 'border-primary ring-primary shadow-md ring-2'
                                                        : 'border-border hover:border-primary/40 hover:shadow-md'
                                                }`}
                                                onClick={() => handleSelect(item.url)}
                                                title={item.file_name}
                                            >
                                                <div className="bg-muted/30 relative flex aspect-square items-center justify-center overflow-hidden">
                                                    {showImagePreview ? (
                                                        <img
                                                            src={item.thumb_url || item.url}
                                                            alt={item.name}
                                                            className="h-full w-full object-cover"
                                                            onError={() => {
                                                                setFailedPreviews((prev) => ({ ...prev, [item.id]: true }));
                                                            }}
                                                        />
                                                    ) : (
                                                        <div className="text-muted-foreground flex h-full w-full flex-col items-center justify-center gap-3 p-4">
                                                            <div className="bg-background rounded-lg border p-3 shadow-sm">
                                                                {getFileIcon(item.mime_type, previewFailed)}
                                                            </div>
                                                            <span className="bg-background text-foreground rounded border px-2 py-0.5 text-[11px] font-semibold">
                                                                {getFileExtension(item)}
                                                            </span>
                                                            {previewFailed && (
                                                                <span className="text-muted-foreground text-[11px]">Preview unavailable</span>
                                                            )}
                                                        </div>
                                                    )}

                                                    {isSelected && (
                                                        <div className="bg-primary/15 absolute inset-0 flex items-center justify-center backdrop-blur-[1px]">
                                                            <div className="bg-primary text-primary-foreground rounded-full p-2 shadow-lg">
                                                                <Check className="h-4 w-4" />
                                                            </div>
                                                        </div>
                                                    )}
                                                </div>

                                                <div className="bg-background border-t px-3 py-2">
                                                    <p className="text-foreground truncate text-xs font-medium" title={item.name}>
                                                        {item.name || item.file_name}
                                                    </p>
                                                    <p className="text-muted-foreground mt-0.5 truncate text-[11px]">
                                                        {getFileExtension(item)} - {formatFileSize(item.size)}
                                                    </p>
                                                </div>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Pagination */}
                    {totalPages > 1 && (
                        <div className="flex items-center justify-between border-t pt-3">
                            <div className="text-muted-foreground text-sm">
                                Showing {startIndex + 1} to {Math.min(startIndex + itemsPerPage, filteredMedia.length)} of {filteredMedia.length}{' '}
                                files
                            </div>
                            <div className="flex gap-1">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={currentPage === 1}
                                    onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
                                >
                                    Previous
                                </Button>
                                {Array.from({ length: Math.min(totalPages, 5) }, (_, i) => {
                                    let page;
                                    if (totalPages <= 5) {
                                        page = i + 1;
                                    } else if (currentPage <= 3) {
                                        page = i + 1;
                                    } else if (currentPage >= totalPages - 2) {
                                        page = totalPages - 4 + i;
                                    } else {
                                        page = currentPage - 2 + i;
                                    }

                                    return (
                                        <Button
                                            key={page}
                                            variant={currentPage === page ? 'default' : 'outline'}
                                            size="sm"
                                            className="h-8 w-8 p-0"
                                            onClick={() => setCurrentPage(page)}
                                        >
                                            {page}
                                        </Button>
                                    );
                                })}
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={currentPage === totalPages}
                                    onClick={() => setCurrentPage((prev) => Math.min(prev + 1, totalPages))}
                                >
                                    Next
                                </Button>
                            </div>
                        </div>
                    )}

                    {/* Actions */}
                    <div className="bg-muted/20 -mx-6 flex items-center justify-between border-t px-6 py-4 pt-6">
                        <Button variant="outline" onClick={onClose} className="px-6">
                            Cancel
                        </Button>
                        <div className="flex gap-3">
                            {multiple && selectedItems.length > 0 && (
                                <Button variant="outline" onClick={() => setSelectedItems([])} className="px-4">
                                    Clear Selection
                                </Button>
                            )}
                            {multiple && selectedItems.length > 0 && (
                                <Button onClick={handleConfirmSelection} className="px-6">
                                    Select {selectedItems.length} item{selectedItems.length > 1 ? 's' : ''}
                                </Button>
                            )}
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
