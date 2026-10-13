import DaftarUlangDetailPage from '@/components/admin/DaftarUlangDetailPage';

export default async function Page({ params }: { params: Promise<{ id: string }> }) {
    const { id } = await params;
    const numericId = Number(id);
    return <DaftarUlangDetailPage id={numericId} />;
}
