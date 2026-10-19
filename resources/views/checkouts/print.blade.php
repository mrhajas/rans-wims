{{-- Redesigned: rendered by the shared print/document layout, fed by App\Support\PrintDocuments. --}}
@include('print.document', ['doc' => \App\Support\PrintDocuments::checkout($checkout)])
