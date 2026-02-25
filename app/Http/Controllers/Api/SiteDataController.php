<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Contact;
use App\Models\CreatePage;
use App\Models\EcomPixel;
use App\Models\SocialMedia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SiteDataController extends Controller
{
    public function index()
    {
        $contact = Contact::where('status', 1)->first();

        $pagesQuery = CreatePage::where('status', 1);
        if (Schema::hasColumn('create_pages', 'footer_sort_order')) {
            $pagesQuery->orderBy('footer_sort_order')->orderBy('id');
        } else {
            $pagesQuery->orderBy('id');
        }
        $pages = $pagesQuery->get();
        [$usefulPages, $referencePages] = $this->resolveFooterPages($pages);

        $socialLinksQuery = SocialMedia::where('status', 1);
        if (Schema::hasColumn('social_media', 'sort_order')) {
            $socialLinksQuery->orderBy('sort_order')->orderBy('id');
        } else {
            $socialLinksQuery->orderBy('id');
        }

        $socialColumns = ['id', 'title', 'icon', 'status'];
        $hasSocialUrl = Schema::hasColumn('social_media', 'url');
        $hasSocialSortOrder = Schema::hasColumn('social_media', 'sort_order');

        if ($hasSocialUrl) {
            $socialColumns[] = 'url';
        }
        if ($hasSocialSortOrder) {
            $socialColumns[] = 'sort_order';
        }

        $socialLinks = $socialLinksQuery->get($socialColumns)->map(function ($item) use ($hasSocialSortOrder, $hasSocialUrl) {
            if (!$hasSocialUrl) {
                $item->url = '';
            }
            if (!$hasSocialSortOrder) {
                $item->sort_order = 0;
            }
            return $item;
        })->values();

        $menuCategories = Category::where('status', 1)
            ->orderBy('id', 'asc')
            ->take(8)
            ->with([
                'subcategories:id,subcategoryName,slug,category_id,status',
                'subcategories.childcategories:id,childcategoryName,slug,subcategory_id,status',
            ])
            ->get();
        $pixels = EcomPixel::where('status', 1)
            ->orderByDesc('id')
            ->get(['id', 'code']);

        return response()->json([
            'success' => true,
            'data' => [
                'contact' => $contact,
                'pages' => $pages,
                'pages_right' => $referencePages,
                'footer_links' => [
                    'useful' => $usefulPages,
                    'references' => $referencePages,
                ],
                'social_links' => $socialLinks,
                'menu_categories' => $menuCategories,
                'pixels' => $pixels,
            ],
        ]);
    }

    /**
     * @return array{0: Collection<int, mixed>, 1: Collection<int, mixed>}
     */
    private function resolveFooterPages(Collection $pages): array
    {
        if (!Schema::hasColumn('create_pages', 'footer_section')) {
            return [$pages, $pages->slice(3, 10)->values()];
        }

        $usefulPages = $pages
            ->filter(fn ($page) => ($page->footer_section ?? 'useful') === 'useful')
            ->values();
        $referencePages = $pages
            ->filter(fn ($page) => ($page->footer_section ?? '') === 'reference')
            ->values();

        if ($usefulPages->isEmpty() && $referencePages->isEmpty()) {
            return [$pages, $pages->slice(3, 10)->values()];
        }

        return [$usefulPages, $referencePages];
    }
}
