import { bootstrapApplication } from "@angular/platform-browser";
import { registerLocaleData } from "@angular/common";
import localeRu from "@angular/common/locales/ru";
import { AppComponent } from "./app/app.component";
import { appConfig } from "./app/app.config";

registerLocaleData(localeRu);
bootstrapApplication(AppComponent, appConfig).catch((error: unknown) =>
  console.error(error),
);
