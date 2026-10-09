import { AsyncPipe, DecimalPipe } from "@angular/common";
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  OnDestroy,
  inject,
  signal,
  viewChild,
} from "@angular/core";
import { ActivatedRoute, RouterLink } from "@angular/router";
import { catchError, map, of, startWith, switchMap } from "rxjs";
import { Product, ProductImage } from "../../models/product";
import { ProductService } from "../../services/product.service";
import { errorMessage } from "../../core/error-message";

interface DetailView {
  loading: boolean;
  product: Product | null;
  error: string;
}

interface SelectedImage {
  path: string;
  alt: string;
  number: number;
}

@Component({
  selector: "app-product-detail",
  imports: [AsyncPipe, DecimalPipe, RouterLink],
  templateUrl: "./product-detail.component.html",
  styleUrl: "./product-detail.component.css",
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductDetailComponent implements OnDestroy {
  private readonly service = inject(ProductService);
  private readonly route = inject(ActivatedRoute);
  private readonly imageDialog =
    viewChild<ElementRef<HTMLDialogElement>>("imageDialog");
  private imageTrigger: HTMLButtonElement | null = null;
  private backdropPointerDown = false;
  readonly selectedImage = signal<SelectedImage | null>(null);
  readonly view$ = this.route.paramMap.pipe(
    switchMap((params) => {
      const id = Number(params.get("id"));
      if (!Number.isInteger(id) || id < 1)
        return of<DetailView>({
          loading: false,
          product: null,
          error: "Некорректный номер товара.",
        });
      return this.service.getProduct(id).pipe(
        map((product): DetailView => ({ loading: false, product, error: "" })),
        catchError((error: unknown) =>
          of<DetailView>({
            loading: false,
            product: null,
            error: errorMessage(error),
          }),
        ),
        startWith<DetailView>({ loading: true, product: null, error: "" }),
      );
    }),
  );

  openImage(
    image: ProductImage,
    productName: string,
    number: number,
    trigger: HTMLButtonElement,
  ): void {
    const dialog = this.imageDialog()?.nativeElement;
    if (!dialog) return;
    this.imageTrigger = trigger;
    this.backdropPointerDown = false;
    this.selectedImage.set({
      path: image.path,
      alt: `${productName}, фото ${number}`,
      number,
    });
    if (!dialog.open) dialog.showModal();
  }

  closeImage(): void {
    this.imageDialog()?.nativeElement.close();
  }

  onImageClosed(): void {
    this.selectedImage.set(null);
    this.backdropPointerDown = false;
    if (this.imageTrigger?.isConnected)
      this.imageTrigger.focus({ preventScroll: true });
    this.imageTrigger = null;
  }

  onViewerPointerDown(event: PointerEvent): void {
    this.backdropPointerDown = this.isBackdropEvent(event);
  }

  onViewerClick(event: MouseEvent): void {
    if (this.backdropPointerDown && this.isBackdropEvent(event))
      this.closeImage();
    this.backdropPointerDown = false;
  }

  onViewerKeydown(event: KeyboardEvent): void {
    const dialog = this.imageDialog()?.nativeElement;
    if (!dialog?.open || event.key !== "Tab" || event.ctrlKey || event.altKey || event.metaKey)
      return;
    // The close button is the only interactive element in this viewer.
    // Native dialogs can otherwise send Tab to browser chrome instead of wrapping focus.
    event.preventDefault();
    dialog.querySelector<HTMLButtonElement>(".image-viewer-close")?.focus();
  }

  ngOnDestroy(): void {
    this.closeImage();
    this.imageTrigger = null;
  }

  private isBackdropEvent(event: MouseEvent): boolean {
    const dialog = this.imageDialog()?.nativeElement;
    if (!dialog || event.target !== dialog) return false;
    const bounds = dialog.getBoundingClientRect();
    return (
      event.clientX < bounds.left ||
      event.clientX > bounds.right ||
      event.clientY < bounds.top ||
      event.clientY > bounds.bottom
    );
  }
}
