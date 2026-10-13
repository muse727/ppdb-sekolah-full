import libAxios from '@/lib/axios';
import type { AdminReportFilters, ReportKind } from './domain';

export async function downloadAdminReport(kind: ReportKind, filters: AdminReportFilters): Promise<void> {
    // Fetch fresh server data with current filters, not the current page's cached rows.
    const response = await libAxios.get<Blob>(`/api/admin/data-${kind}/export`, { params: filters, responseType: 'blob' });
    const url = URL.createObjectURL(response.data);
    const link = document.createElement('a');
    link.href = url;
    link.download = `data-${kind}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
