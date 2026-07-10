<?php

use App\Filament\Resources\CorpusDocuments\CorpusDocumentResource;
use App\Models\Corpus\CorpusDocument;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

describe('corpus documents Filament resource', function () {
    it('redirects guests to the panel login', function () {
        $this->get(CorpusDocumentResource::getUrl('index'))
            ->assertRedirect();
    });

    it('loads the list page for an admin', function () {
        CorpusDocument::factory()->ready()->create(['title' => 'لائحة الدراسة والاختبارات']);
        CorpusDocument::factory()->failed()->create();

        $this->actingAs($this->admin)
            ->get(CorpusDocumentResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee('مستندات الذكاء الاصطناعي');
    });

    it('loads the upload (create) page for an admin', function () {
        $this->actingAs($this->admin)
            ->get(CorpusDocumentResource::getUrl('create'))
            ->assertSuccessful();
    });

    it('loads the edit page with the extracted markdown for an admin', function () {
        $document = CorpusDocument::factory()->ready()->create();

        $this->actingAs($this->admin)
            ->get(CorpusDocumentResource::getUrl('edit', ['record' => $document]))
            ->assertSuccessful();
    });
});
