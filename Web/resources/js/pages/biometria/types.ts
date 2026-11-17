export type FaceEnrollmentRow = {
    id: number;
    status: 'active' | 'revoked';
    active: boolean;
    provider: string;
    template_version: string | null;
    consent_at: string | null;
    consent_document: string | null;
    consent_document_sha256: string | null;
    identity_verified_by: string | null;
    revoked_at: string | null;
    revoked_by: string | null;
    revocation_reason: string | null;
    template_erased_at: string | null;
    liveness_score: number | null;
};

export type FaceEnrollmentPageProps = {
    employee: { id: number; full_name: string; employee_number: string };
    service: { available: boolean; provider: string | null };
    consentDocument: {
        id: number;
        title: string;
        version: string;
        sha256: string;
    } | null;
    enrollments: FaceEnrollmentRow[];
    limits: { min_frames: number; max_frames: number };
};
