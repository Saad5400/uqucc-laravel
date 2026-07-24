/** Laravel's default length-aware paginator shape (the fields we use). */
export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}
