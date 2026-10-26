import React from 'react';
import { FEATURED_PRODUCTS } from '../constants';
import { ProductCard } from './Products';

const FeaturedProducts: React.FC = () => {
  return (
    <section className="py-20 sm:py-24 bg-gray-50 border-t border-b border-gray-200">
      <div className="container mx-auto px-4 sm:px-6 lg:px-8">
        <div className="text-center mb-12">
          <h2 className="text-3xl sm:text-4xl font-bold tracking-tight text-gray-900">Featured & New Arrivals</h2>
          <p className="mt-4 max-w-2xl mx-auto text-lg text-gray-600">
            Handpicked essentials to kickstart your journey to peak performance.
          </p>
        </div>
        <div className="grid grid-cols-1 gap-y-10 sm:grid-cols-2 lg:grid-cols-4 gap-x-8">
          {FEATURED_PRODUCTS.map((product) => (
            <ProductCard key={product.id} product={product} />
          ))}
        </div>
      </div>
    </section>
  );
};

export default FeaturedProducts;